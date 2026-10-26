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

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\ResourcePackChunkDataPacket;
use pocketmine\network\mcpe\protocol\ResourcePackDataInfoPacket;
use pocketmine\network\mcpe\protocol\ResourcePacksInfoPacket;
use pocketmine\network\mcpe\protocol\ResourcePackStackPacket;
use pocketmine\network\mcpe\protocol\types\Experiments;
use pocketmine\network\mcpe\protocol\types\resourcepacks\ResourcePackInfoEntry;
use pocketmine\network\mcpe\protocol\types\resourcepacks\ResourcePackStackEntry;
use pocketmine\network\mcpe\protocol\types\resourcepacks\ResourcePackType;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\Packet;
use quark\network\PacketHandlingException;
use quark\promise\Promise;
use quark\promise\PromiseResolver;
use quark\resourcepacks\ResourcePack;
use Ramsey\Uuid\Uuid;
use function array_map;
use function bin2hex;
use function str_repeat;

final class ResourcePacksPacketHandlerTest extends TestCase{
	private const PACK_ID = "0d2d6c31-3c0b-4dc3-bd80-9c5f5a3b1c21";
	private const PACK_SIZE = 300 * 1024;

	/** @var list<Packet> */
	private array $sent = [];
	private bool $completed = false;

	private static function legacy(ClientboundPacket $packet) : string{
		$writer = new ByteBufferWriter();
		$packet->encode($writer);
		return $writer->getData();
	}

	/**
	 * @phpstan-return NetworkSession&MockObject
	 */
	private function session() : NetworkSession{
		$session = $this->createMock(NetworkSession::class);
		$session->method("sendDataPacket")->willReturnCallback(function(Packet $packet) : bool{
			$this->sent[] = $packet;
			return true;
		});
		$session->method("sendDataPacketWithReceipt")->willReturnCallback(function(Packet $packet) : Promise{
			$this->sent[] = $packet;
			/** @phpstan-var PromiseResolver<true> $resolver */
			$resolver = new PromiseResolver();
			$resolver->resolve(true);
			return $resolver->getPromise();
		});
		return $session;
	}

	private function handler(NetworkSession $session) : ResourcePacksPacketHandler{
		$pack = $this->createMock(ResourcePack::class);
		$pack->method("getPackId")->willReturn(self::PACK_ID);
		$pack->method("getPackVersion")->willReturn("1.0.0");
		$pack->method("getPackSize")->willReturn(self::PACK_SIZE);
		$pack->method("getSha256")->willReturn(str_repeat("\xab", 32));
		$pack->method("getPackChunk")->willReturnCallback(fn(int $start, int $length) : string => "chunk at $start");

		return new ResourcePacksPacketHandler($session, [$pack], [self::PACK_ID => "key"], true, function() : void{
			$this->completed = true;
		});
	}

	public function testDownloadSequenceMatchesBedrockProtocol() : void{
		$handler = $this->handler($this->session());
		$handler->setUp();
		self::assertTrue($handler->handleResourcePackClientResponse(["response" => ["downloading" => ["response_type" => "downloading", "downloading_packs" => [self::PACK_ID . "_1.0.0"]]]]));
		self::assertTrue($handler->handleResourcePackChunkRequest(["resource_name" => self::PACK_ID, "chunk" => 1]));
		self::assertTrue($handler->handleResourcePackClientResponse(["response" => ["downloading_finished" => "downloadingfinished"]]));
		self::assertTrue($handler->handleResourcePackClientResponse(["response" => ["resource_pack_stack_finished" => "resourcepackstackfinished"]]));
		self::assertTrue($this->completed);

		/** @phpstan-var list<array{string, string}> $chemistryPacks */
		$chemistryPacks = (new \ReflectionClassConstant(ResourcePacksPacketHandler::class, "CHEMISTRY_RESOURCE_PACKS"))->getValue();
		$stack = [new ResourcePackStackEntry(self::PACK_ID, "1.0.0", "")];
		foreach($chemistryPacks as [$uuid, $version]){
			$stack[] = new ResourcePackStackEntry($uuid, $version, "");
		}
		$expected = [
			ResourcePacksInfoPacket::create(
				resourcePackEntries: [new ResourcePackInfoEntry(Uuid::fromString(self::PACK_ID), "1.0.0", self::PACK_SIZE, "key", "", self::PACK_ID, false)],
				mustAccept: true,
				hasAddons: false,
				hasScripts: false,
				worldTemplateId: Uuid::fromString(Uuid::NIL),
				worldTemplateVersion: "",
				forceDisableVibrantVisuals: true,
			),
			ResourcePackDataInfoPacket::create(self::PACK_ID, 256 * 1024, 2, self::PACK_SIZE, str_repeat("\xab", 32), false, ResourcePackType::RESOURCES),
			ResourcePackChunkDataPacket::create(self::PACK_ID, 1, 256 * 1024, "chunk at 262144"),
			ResourcePackStackPacket::create($stack, false, ProtocolInfo::MINECRAFT_VERSION_NETWORK, new Experiments([], false), false),
		];
		self::assertSame(
			array_map(fn(ClientboundPacket $packet) : string => bin2hex(self::legacy($packet)), $expected),
			array_map(fn(Packet $packet) : string => bin2hex($packet->getEncoded()), $this->sent)
		);
	}

	public function testRejectsMismatchedResponseType() : void{
		$handler = $this->handler($this->session());
		$this->expectException(PacketHandlingException::class);
		$handler->handleResourcePackClientResponse(["response" => ["downloading_finished" => "resourcepackstackfinished"]]);
	}

	public function testRejectsRepeatedMetadataRequests() : void{
		$handler = $this->handler($this->session());
		$request = ["response" => ["downloading" => ["response_type" => "downloading", "downloading_packs" => [self::PACK_ID]]]];
		$handler->handleResourcePackClientResponse($request);
		$this->expectException(PacketHandlingException::class);
		$handler->handleResourcePackClientResponse($request);
	}

	/**
	 * @return int[][]
	 */
	public static function invalidChunkProvider() : array{
		return [[2], [0xffffffff]];
	}

	/**
	 * @dataProvider invalidChunkProvider
	 */
	public function testDisconnectsOnOutOfBoundsChunk(int $chunk) : void{
		$session = $this->session();
		$session->expects(self::once())->method("disconnectWithError");
		self::assertFalse($this->handler($session)->handleResourcePackChunkRequest(["resource_name" => self::PACK_ID, "chunk" => $chunk]));
		self::assertSame([], $this->sent);
	}

	public function testDisconnectsOnDuplicateChunk() : void{
		$session = $this->session();
		$session->expects(self::once())->method("disconnectWithError");
		$handler = $this->handler($session);
		self::assertTrue($handler->handleResourcePackChunkRequest(["resource_name" => self::PACK_ID, "chunk" => 0]));
		self::assertFalse($handler->handleResourcePackChunkRequest(["resource_name" => self::PACK_ID, "chunk" => 0]));
	}

	public function testDisconnectsOnUnknownPack() : void{
		$session = $this->session();
		$session->expects(self::once())->method("disconnectWithError");
		self::assertFalse($this->handler($session)->handleResourcePackChunkRequest(["resource_name" => Uuid::NIL, "chunk" => 0]));
	}
}
