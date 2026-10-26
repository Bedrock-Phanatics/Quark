<?php

/*
 *
 *   ___  _   _   _    ____  _  __
 *  / _ \| | | | / \  |  _ \| |/ /
 * | | | | | | |/ _ \ | |_) | ' /
 * | |_| | |_| / ___ \|  _ <| . \
 *  \__\_|\___/_/   \_\_| \_\_|\_\
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Quark Team
 * @link https://github.com/Bedrock-Phanatics/Quark
 *
 *
 */

declare(strict_types=1);

namespace quark\network\mcpe\handler;

use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\ResourcePackClientResponseStatus;
use pocketmine\network\mcpe\protocol\types\resourcepacks\ResourcePackType;
use quark\lang\KnownTranslationFactory;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\Fields;
use quark\network\mcpe\protocol\Packets;
use quark\network\PacketHandlingException;
use quark\resourcepacks\ResourcePack;
use Ramsey\Uuid\Uuid;
use function array_key_first;
use function array_keys;
use function array_map;
use function ceil;
use function count;
use function implode;
use function is_array;
use function sprintf;
use function strpos;
use function strtolower;
use function substr;

/**
 * Handler used for the resource packs sequence phase of the session. This handler takes care of downloading resource
 * packs to the client.
 *
 * @phpstan-import-type ResourcePackClientResponseFields from Packets
 * @phpstan-import-type ResourcePackChunkRequestFields from Packets
 */
class ResourcePacksPacketHandler extends PacketHandler{
	private const PACK_CHUNK_SIZE = 256 * 1024; //256KB

	/**
	 * Larger values allow downloading more chunks at the same time, increasing download speed, but the client may choke
	 * and cause the download speed to drop (due to ACKs taking too long to arrive).
	 */
	private const MAX_CONCURRENT_CHUNK_REQUESTS = 1;

	private const RESPONSE_STATUSES = [
		"cancel" => ResourcePackClientResponseStatus::REFUSED,
		"downloading" => ResourcePackClientResponseStatus::SEND_PACKS,
		"downloading_finished" => ResourcePackClientResponseStatus::HAVE_ALL_PACKS,
		"resource_pack_stack_finished" => ResourcePackClientResponseStatus::COMPLETED,
	];

	/**
	 * All chemistry resource packs need to be listed here to get chemistry blocks to render
	 * correctly, unfortunately there doesn't seem to be a better way to do this
	 */
	private const CHEMISTRY_RESOURCE_PACKS = [
		["b41c2785-c512-4a49-af56-3a87afd47c57", "1.21.30"],
		["a4df0cb3-17be-4163-88d7-fcf7002b935d", "1.21.20"],
		["d19adffe-a2e1-4b02-8436-ca4583368c89", "1.21.10"],
		["85d5603d-2824-4b21-8044-34f441f4fce1", "1.21.0"],
		["e977cd13-0a11-4618-96fb-03dfe9c43608", "1.20.60"],
		["0674721c-a0aa-41a1-9ba8-1ed33ea3e7ed", "1.20.50"],
		["0fba4063-dba1-4281-9b89-ff9390653530", "1.0.0"],
	];

	/**
	 * @var ResourcePack[]
	 * @phpstan-var array<string, ResourcePack>
	 */
	private array $resourcePacksById = [];

	private bool $requestedMetadata = false;
	private bool $requestedStack = false;

	/** @var bool[][] uuid => [chunk index => hasSent] */
	private array $downloadedChunks = [];

	/** @phpstan-var \SplQueue<array{ResourcePack, int}> */
	private \SplQueue $requestQueue;

	private int $activeRequests = 0;

	/**
	 * @param ResourcePack[] $resourcePackStack
	 * @param string[]       $encryptionKeys    pack UUID => key, leave unset for any packs that are not encrypted
	 *
	 * @phpstan-param list<ResourcePack>    $resourcePackStack
	 * @phpstan-param array<string, string> $encryptionKeys
	 * @phpstan-param \Closure() : void     $completionCallback
	 */
	public function __construct(
		private NetworkSession $session,
		private array $resourcePackStack,
		private array $encryptionKeys,
		private bool $mustAccept,
		private \Closure $completionCallback
	){
		$this->requestQueue = new \SplQueue();
		foreach($resourcePackStack as $pack){
			$this->resourcePacksById[$pack->getPackId()] = $pack;
		}
	}

	private function getPackById(string $id) : ?ResourcePack{
		return $this->resourcePacksById[strtolower($id)] ?? null;
	}

	public function setUp() : void{
		$resourcePackEntries = array_map(fn(ResourcePack $pack) : array => [ //TODO: more stuff
			"pack_id_version" => ["pack_uuid" => Fields::uuid(Uuid::fromString($pack->getPackId())), "pack_version" => $pack->getPackVersion()],
			"pack_size" => $pack->getPackSize(),
			"content_key" => $this->encryptionKeys[$pack->getPackId()] ?? "",
			"subpack_name" => "",
			"content_identity" => $pack->getPackId(),
			"has_scripts" => false,
			"is_addon_pack" => false,
			"is_ray_tracing_capable" => false,
			"cdn_url" => "",
		], $this->resourcePackStack);
		//TODO: support forcing server packs
		$this->session->sendDataPacket(Packets::resourcePacksInfo([
			"resource_pack_required" => $this->mustAccept,
			"has_addon_packs" => false,
			"has_scripts" => false,
			"force_disable_vibrant_visuals" => true,
			"world_template_id_and_version" => ["pack_uuid" => Fields::uuid(Uuid::fromString(Uuid::NIL)), "pack_version" => ""],
			"resource_packs" => $resourcePackEntries,
		]));
		$this->session->getLogger()->debug("Waiting for client to accept resource packs");
	}

	private function disconnectWithError(string $error) : void{
		$this->session->disconnectWithError(
			reason: "Error downloading resource packs: " . $error,
			disconnectScreenMessage: KnownTranslationFactory::disconnectionScreen_resourcePack()
		);
	}

	/**
	 * @phpstan-param ResourcePackClientResponseFields $packet
	 */
	public function handleResourcePackClientResponse(array $packet) : bool{
		$response = $packet["response"];
		$variant = array_key_first($response);
		$status = self::RESPONSE_STATUSES[$variant];
		$body = $response[$variant];
		$innerType = is_array($body) ? $body["response_type"] : $body;
		if($innerType !== $status->value){
			throw new PacketHandlingException("Unexpected inner type $innerType for resource pack client response status $status->name, expected $status->value");
		}
		$packIds = is_array($body) ? $body["downloading_packs"] : [];

		switch($status){
			case ResourcePackClientResponseStatus::REFUSED:
				//TODO: add lang strings for this
				$this->session->disconnect("Refused resource packs", "You must accept resource packs to join this server.", true);
				break;
			case ResourcePackClientResponseStatus::SEND_PACKS:
				if($this->requestedMetadata){
					throw new PacketHandlingException("Cannot request resource pack metadata multiple times");
				}
				$this->requestedMetadata = true;

				if($this->requestedStack){
					//client already told us that they have all the packs, they shouldn't be asking for more
					throw new PacketHandlingException("Cannot request resource pack metadata after resource pack stack");
				}

				if(count($packIds) > count($this->resourcePacksById)){
					throw new PacketHandlingException(sprintf("Requested metadata for more resource packs (%d) than available on the server (%d)", count($packIds), count($this->resourcePacksById)));
				}

				$seen = [];
				foreach($packIds as $uuid){
					//dirty hack for mojang's dirty hack for versions
					$splitPos = strpos($uuid, "_");
					if($splitPos !== false){
						$uuid = substr($uuid, 0, $splitPos);
					}
					$pack = $this->getPackById($uuid);

					if(!($pack instanceof ResourcePack)){
						//Client requested a resource pack but we don't have it available on the server
						$this->disconnectWithError("Unknown pack $uuid requested, available packs: " . implode(", ", array_keys($this->resourcePacksById)));
						return false;
					}
					if(isset($seen[$pack->getPackId()])){
						throw new PacketHandlingException("Repeated metadata request for pack $uuid");
					}

					$this->session->sendDataPacket(Packets::resourcePackDataInfo([
						"resource_name" => $pack->getPackId(),
						"chunk_size" => self::PACK_CHUNK_SIZE,
						"number_of_chunks" => (int) ceil($pack->getPackSize() / self::PACK_CHUNK_SIZE),
						"file_size" => $pack->getPackSize(),
						"file_hash" => $pack->getSha256(),
						"is_premium_pack" => false,
						"pack_type" => ResourcePackType::RESOURCES->value, //TODO: this might be an addon (not behaviour pack), needed to properly support client-side custom items
					]));
					$seen[$pack->getPackId()] = true;
				}
				$this->session->getLogger()->debug("Player requested download of " . count($packIds) . " resource packs");
				break;
			case ResourcePackClientResponseStatus::HAVE_ALL_PACKS:
				if($this->requestedStack){
					throw new PacketHandlingException("Cannot request resource pack stack multiple times");
				}
				$this->requestedStack = true;

				$stack = array_map(static fn(ResourcePack $pack) : array => [
					"pack_id" => $pack->getPackId(),
					"version" => $pack->getPackVersion(),
					"sub_pack_name" => "", //TODO: subpacks
				], $this->resourcePackStack);

				//we support chemistry blocks by default, the client should already have these installed
				foreach(self::CHEMISTRY_RESOURCE_PACKS as [$uuid, $version]){
					$stack[] = ["pack_id" => $uuid, "version" => $version, "sub_pack_name" => ""];
				}

				//we don't force here, because it doesn't have user-facing effects
				//but it does have an annoying side-effect when true: it makes
				//the client remove its own non-server-supplied resource packs.
				$this->session->sendDataPacket(Packets::resourcePackStack([
					"texture_pack_required" => false,
					"texture_pack_list" => $stack,
					"base_game_version" => ProtocolInfo::MINECRAFT_VERSION_NETWORK,
					"experiments" => ["toggles" => [], "experiments_ever_toggled" => false],
					"include_editor_packs" => false,
				]));
				$this->session->getLogger()->debug("Applying resource pack stack");
				break;
			case ResourcePackClientResponseStatus::COMPLETED:
				$this->session->getLogger()->debug("Resource packs sequence completed");
				if($this->session->getHandler() === $this){
					$this->session->setHandler(null);
				}
				($this->completionCallback)();
				break;
		}

		return true;
	}

	/**
	 * @phpstan-param ResourcePackChunkRequestFields $packet
	 */
	public function handleResourcePackChunkRequest(array $packet) : bool{
		$requestedId = $packet["resource_name"];
		$chunkIndex = $packet["chunk"];
		$pack = $this->getPackById($requestedId);
		if(!($pack instanceof ResourcePack)){
			$this->disconnectWithError("Invalid request for chunk $chunkIndex of unknown pack $requestedId, available packs: " . implode(", ", array_keys($this->resourcePacksById)));
			return false;
		}

		$packId = $pack->getPackId(); //use this because case may be different

		if(isset($this->downloadedChunks[$packId][$chunkIndex])){
			$this->disconnectWithError("Duplicate request for chunk $chunkIndex of pack $requestedId");
			return false;
		}

		$offset = $chunkIndex * self::PACK_CHUNK_SIZE;
		if($offset < 0 || $offset >= $pack->getPackSize()){
			$this->disconnectWithError("Invalid out-of-bounds request for chunk $chunkIndex of $requestedId: offset $offset, file size " . $pack->getPackSize());
			return false;
		}

		if(!isset($this->downloadedChunks[$packId])){
			$this->downloadedChunks[$packId] = [$chunkIndex => true];
		}else{
			$this->downloadedChunks[$packId][$chunkIndex] = true;
		}

		$this->requestQueue->enqueue([$pack, $chunkIndex]);
		$this->processChunkRequestQueue();

		return true;
	}

	private function processChunkRequestQueue() : void{
		if($this->activeRequests >= self::MAX_CONCURRENT_CHUNK_REQUESTS || $this->requestQueue->isEmpty()){
			return;
		}
		/**
		 * @var ResourcePack $pack
		 * @var int          $chunkIndex
		 */
		[$pack, $chunkIndex] = $this->requestQueue->dequeue();

		$packId = $pack->getPackId();
		$offset = $chunkIndex * self::PACK_CHUNK_SIZE;
		$chunkData = $pack->getPackChunk($offset, self::PACK_CHUNK_SIZE);
		$this->activeRequests++;
		$this->session
			->sendDataPacketWithReceipt(Packets::resourcePackChunkData([
				"resource_name" => $packId,
				"chunk_id" => $chunkIndex,
				"byte_offset" => $offset,
				"chunk_data" => $chunkData,
			]))
			->onCompletion(
				function() : void{
					$this->activeRequests--;
					$this->processChunkRequestQueue();
				},
				function() : void{
					//this may have been rejected because of a disconnection - this will do nothing in that case
					$this->disconnectWithError("Plugin interrupted sending of resource packs");
				}
			);
	}
}
