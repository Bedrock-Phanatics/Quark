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

namespace quark\network\mcpe;

use Bedwire\PacketException;
use Bedwire\PacketId;
use Bedwire\Protocol;
use pmmp\encoding\Byte;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\VarInt;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\serializer\CommonTypes;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\SetSpawnPositionPacket;
use pocketmine\network\mcpe\protocol\SetTitlePacket;
use pocketmine\network\mcpe\protocol\types\AbilitiesData;
use pocketmine\network\mcpe\protocol\types\AbilitiesLayer;
use pocketmine\network\mcpe\protocol\types\command\CommandPermissions;
use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use pocketmine\network\mcpe\protocol\types\PlayerPermissions;
use pocketmine\network\mcpe\protocol\UpdateAbilitiesPacket;
use pocketmine\utils\Limits;
use quark\entity\effect\EffectInstance;
use quark\event\player\PlayerDuplicateLoginEvent;
use quark\event\player\PlayerIdentityVerifyEvent;
use quark\event\player\PlayerResourcePackOfferEvent;
use quark\event\server\DataPacketDecodeEvent;
use quark\event\server\DataPacketReceiveEvent;
use quark\event\server\DataPacketSendEvent;
use quark\form\Form;
use quark\item\Item;
use quark\lang\KnownTranslationFactory;
use quark\lang\Translatable;
use quark\network\FilterNoisyPacketException;
use quark\network\mcpe\cache\ChunkCache;
use quark\network\mcpe\compression\CompressBatchPromise;
use quark\network\mcpe\compression\Compressor;
use quark\network\mcpe\compression\DecompressionException;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\encryption\DecryptionException;
use quark\network\mcpe\encryption\EncryptionContext;
use quark\network\mcpe\encryption\PrepareEncryptionTask;
use quark\network\mcpe\handler\DeathPacketHandler;
use quark\network\mcpe\handler\HandshakePacketHandler;
use quark\network\mcpe\handler\InGamePacketHandler;
use quark\network\mcpe\handler\LoginPacketHandler;
use quark\network\mcpe\handler\PacketHandler;
use quark\network\mcpe\handler\PacketHandlerAction;
use quark\network\mcpe\handler\PacketHandlerInspector;
use quark\network\mcpe\handler\PreSpawnPacketHandler;
use quark\network\mcpe\handler\ResourcePacksPacketHandler;
use quark\network\mcpe\handler\SessionStartPacketHandler;
use quark\network\mcpe\handler\SpawnResponsePacketHandler;
use quark\network\mcpe\protocol\Fields;
use quark\network\mcpe\protocol\LegacyPacketAdapter;
use quark\network\mcpe\protocol\Packet;
use quark\network\mcpe\protocol\Packets;
use quark\network\mcpe\protocol\TextPackets;
use quark\network\NetworkSessionManager;
use quark\network\PacketHandlingException;
use quark\network\TransportIdentityException;
use quark\network\TransportIdentityKey;
use quark\permission\DefaultPermissionNames;
use quark\permission\DefaultPermissions;
use quark\player\GameMode;
use quark\player\Player;
use quark\player\PlayerInfo;
use quark\player\UsedChunkStatus;
use quark\player\XboxLivePlayerInfo;
use quark\promise\Promise;
use quark\promise\PromiseResolver;
use quark\pulse\internal\PulseNetwork;
use quark\pulse\internal\PulseNetworkWork;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;
use quark\Server;
use quark\utils\AssumptionFailedError;
use quark\utils\ObjectSet;
use quark\utils\TextFormat;
use quark\utils\Utils;
use quark\world\format\io\GlobalItemDataHandlers;
use quark\world\Position;
use quark\world\World;
use quark\YmlServerProperties;
use function array_map;
use function array_slice;
use function array_values;
use function bin2hex;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_encode;
use function ord;
use function random_bytes;
use function str_split;
use function strcasecmp;
use function strlen;
use function strtolower;
use function substr;
use function time;
use function ucfirst;
use const JSON_THROW_ON_ERROR;

class NetworkSession{
	private const INCOMING_PACKET_BATCH_PER_TICK = 2; //usually max 1 per tick, but transactions arrive separately
	private const INCOMING_PACKET_BATCH_BUFFER_TICKS = 100; //enough to account for a 5-second lag spike

	private const INCOMING_GAME_PACKETS_PER_TICK = 2;
	private const INCOMING_GAME_PACKETS_BUFFER_TICKS = 100;

	private const INCOMING_PACKET_BATCH_HARD_LIMIT = 300;

	/**
	 * Array entries one received packet may decode into. Vanilla packets stay far below this, and it keeps a packet
	 * full of tiny list elements from expanding into hundreds of megabytes of arrays.
	 */
	private const MAX_DECODED_VALUES = 65536;

	private const PRE_LOGIN_PACKETS = [
		PacketId::NETWORK_SETTINGS => true,
		PacketId::PLAY_STATUS => true,
		PacketId::SERVER_TO_CLIENT_HANDSHAKE => true,
		PacketId::DISCONNECT => true,
	];

	private PacketRateLimiter $packetBatchLimiter;
	private PacketRateLimiter $gamePacketLimiter;

	private \PrefixedLogger $logger;
	private ?Player $player = null;
	private ?PlayerInfo $info = null;
	private ?int $ping = null;

	private ?PacketHandler $handler = null;
	/**
	 * @var PacketHandlerAction[]|null
	 * @phpstan-var array<int, PacketHandlerAction>|null
	 */
	private ?array $handlerActions = null;
	/** @phpstan-var array<int, \Closure(mixed) : bool> */
	private array $handlerMethods = [];
	/** @phpstan-var array<int, true> packets whose handler still takes a BedrockProtocol packet object */
	private array $legacyHandlers = [];

	private bool $connected = true;
	private bool $disconnectGuard = false;
	private bool $loggedIn = false;
	private bool $authenticated = false;
	private int $connectTime;
	private ?CompoundTag $cachedOfflinePlayerData = null;

	private ?EncryptionContext $cipher = null;

	/**
	 * Packets waiting for the next flush, as Bedwire\Protocol::encodeBatch() takes them.
	 *
	 * @phpstan-var list<string|array{int, array<string, mixed>}>
	 */
	private array $sendBuffer = [];
	/**
	 * @var PromiseResolver[]
	 * @phpstan-var list<PromiseResolver<true>>
	 */
	private array $sendBufferAckPromises = [];

	/** @phpstan-var \SplQueue<array{CompressBatchPromise|string, list<PromiseResolver<true>>, bool}> */
	private \SplQueue $compressedQueue;
	private bool $latencySensitiveFlushEnabled;
	private bool $latencySensitiveFlushPerformedThisTick = false;
	private bool $forceAsyncCompression = true;
	private bool $enableCompression = false; //disabled until handshake completed

	private int $nextAckReceiptId = 0;
	/**
	 * @var PromiseResolver[][]
	 * @phpstan-var array<int, list<PromiseResolver<true>>>
	 */
	private array $ackPromisesByReceiptId = [];

	private ?InventoryManager $invManager = null;

	/**
	 * @var \Closure[]|ObjectSet
	 * @phpstan-var ObjectSet<\Closure() : void>
	 */
	private ObjectSet $disposeHooks;

	private string $noisyPacketBuffer = "";
	private int $noisyPacketsDropped = 0;
	private int $pulseGeneration = 0;
	private int $pulseSessionId = 0;
	private ?int $protocolVersion = null;
	private string $connectionPhase = "session_start";
	/** @phpstan-var array{string, string, string}|null display name, UUID and XUID the transport verified */
	private ?array $transportVerifiedLogin = null;

	private function getPulseNetwork() : ?PulseNetwork{
		$network = Pulse::getNetworkTelemetry();
		if($network !== null && $this->pulseGeneration !== $network->generation){
			$this->pulseGeneration = $network->generation;
			$this->pulseSessionId = $network->session($this->protocolVersion, $this->connectionPhase);
		}
		return $network;
	}

	/** @internal */
	public function recordProtocolVersion(int $version) : void{
		$this->protocolVersion = $version;
		$this->getPulseNetwork()?->updateSession($this->pulseSessionId, $version, $this->connectionPhase);
	}

	/** @internal */
	public function recordNetworkSecurityEvent(string $reason, string $action, ?int $packetId = null, ?int $observed = null, ?int $limit = null) : void{
		$this->getPulseNetwork()?->event($this->pulseSessionId, $packetId, $this->connectionPhase, $reason, $action, Pulse::getNetworkTickId(), $observed, $limit);
	}

	private function recordRawPacketSecurityEvent(string $buffer, string $reason, ?int $observed = null, ?int $limit = null) : void{
		if($this->getPulseNetwork() === null){ return; }
		try{
			$id = Protocol::packetId($buffer);
		}catch(PacketException){
			$id = null;
		}
		$this->recordNetworkSecurityEvent($reason, "reject_batch", $id, $observed, $limit);
	}

	public function __construct(
		private Server $server,
		private NetworkSessionManager $manager,
		private PacketSender $sender,
		private PacketBroadcaster $broadcaster,
		private EntityEventBroadcaster $entityEventBroadcaster,
		private Compressor $compressor,
		private TypeConverter $typeConverter,
		private string $ip,
		private int $port,
		private ?TransportIdentityKey $transportIdentityKey = null,
		private bool $transportEncrypted = false
	){
		$this->logger = new \PrefixedLogger($this->server->getLogger(), $this->getLogPrefix());

		$this->compressedQueue = new \SplQueue();
		$this->latencySensitiveFlushEnabled = $this->server->isLowLatencyCombatFeedbackEnabled();

		$this->disposeHooks = new ObjectSet();

		$this->connectTime = time();
		$this->packetBatchLimiter = new PacketRateLimiter("Packet Batches", self::INCOMING_PACKET_BATCH_PER_TICK, self::INCOMING_PACKET_BATCH_BUFFER_TICKS);
		$this->gamePacketLimiter = new PacketRateLimiter("Game Packets", self::INCOMING_GAME_PACKETS_PER_TICK, self::INCOMING_GAME_PACKETS_BUFFER_TICKS);

		$this->setHandler(new SessionStartPacketHandler(
			$this,
			$this->onSessionStartSuccess(...)
		));

		$this->manager->add($this);
		$this->logger->info($this->server->getLanguage()->translate(KnownTranslationFactory::quark_network_session_open()));
	}

	private function getLogPrefix() : string{
		return "NetworkSession: " . $this->getDisplayName();
	}

	public function getLogger() : \Logger{
		return $this->logger;
	}

	private function onSessionStartSuccess() : void{
		$this->logger->debug("Session start handshake completed, awaiting login packet");
		$this->flushGamePacketQueue();
		$this->enableCompression = true;
		$this->setHandler(new LoginPacketHandler(
			$this->server,
			$this,
			function(PlayerInfo $info) : void{
				$this->info = $info;
				$this->logger->info($this->server->getLanguage()->translate(KnownTranslationFactory::quark_network_session_playerName(TextFormat::AQUA . $info->getUsername() . TextFormat::RESET)));
				$this->logger->setPrefix($this->getLogPrefix());
				$this->manager->markLoginReceived($this);
			},
			$this->setAuthenticationStatus(...)
		));
	}

	protected function createPlayer() : void{
		$this->server->createPlayer($this, $this->info, $this->authenticated, $this->cachedOfflinePlayerData)->onCompletion(
			$this->onPlayerCreated(...),
			function() : void{
				//TODO: this should never actually occur... right?
				$this->disconnectWithError(
					reason: "Failed to create player",
					disconnectScreenMessage: KnownTranslationFactory::quark_disconnect_error_internal()
				);
			}
		);
	}

	private function onPlayerCreated(Player $player) : void{
		if(!$this->isConnected()){
			//the remote player might have disconnected before spawn terrain generation was finished
			return;
		}
		$this->player = $player;
		if(!$this->server->addOnlinePlayer($player)){
			return;
		}

		$this->invManager = new InventoryManager($this->player, $this);

		$effectManager = $this->player->getEffects();
		$effectManager->getEffectAddHooks()->add($effectAddHook = function(EffectInstance $effect, bool $replacesOldEffect) : void{
			$this->entityEventBroadcaster->onEntityEffectAdded([$this], $this->player, $effect, $replacesOldEffect);
		});
		$effectManager->getEffectRemoveHooks()->add($effectRemoveHook = function(EffectInstance $effect) : void{
			$this->entityEventBroadcaster->onEntityEffectRemoved([$this], $this->player, $effect);
		});
		$this->disposeHooks->add(static function() use ($effectManager, $effectAddHook, $effectRemoveHook) : void{
			$effectManager->getEffectAddHooks()->remove($effectAddHook);
			$effectManager->getEffectRemoveHooks()->remove($effectRemoveHook);
		});

		$permissionHooks = $this->player->getPermissionRecalculationCallbacks();
		$permissionHooks->add($permHook = function() : void{
			$this->logger->debug("Syncing available commands and abilities/permissions due to permission recalculation");
			$this->syncAbilities($this->player);
			$this->syncAvailableCommands();
		});
		$this->disposeHooks->add(static function() use ($permissionHooks, $permHook) : void{
			$permissionHooks->remove($permHook);
		});
		$this->beginSpawnSequence();
	}

	public function getPlayer() : ?Player{
		return $this->player;
	}

	public function getPlayerInfo() : ?PlayerInfo{
		return $this->info;
	}

	public function isConnected() : bool{
		return $this->connected && !$this->disconnectGuard;
	}

	public function getIp() : string{
		return $this->ip;
	}

	public function getPort() : int{
		return $this->port;
	}

	/**
	 * Replaces the connection's address with the player's, as reported by a trusted proxy.
	 */
	public function setProxiedAddress(string $ip, int $port) : void{
		$this->ip = $ip;
		$this->port = $port;
	}

	public function getTransportIdentityKey() : ?TransportIdentityKey{
		return $this->transportIdentityKey;
	}

	/**
	 * @internal Called by transports that verify Microsoft-signed logins themselves, before the Login packet arrives.
	 */
	public function setTransportVerifiedLogin(string $displayName, string $uuid, string $xuid) : void{
		$this->transportVerifiedLogin = [$displayName, $uuid, $xuid];
	}

	/**
	 * Returns the display name, UUID and XUID of a login the transport already verified against Microsoft's keys,
	 * or null if the login still has to be verified here.
	 *
	 * @phpstan-return array{string, string, string}|null
	 */
	public function getTransportVerifiedLogin() : ?array{
		return $this->transportVerifiedLogin;
	}

	public function getDisplayName() : string{
		return $this->info !== null ? $this->info->getUsername() : $this->ip . " " . $this->port;
	}

	/**
	 * Returns the last recorded ping measurement for this session, in milliseconds, or null if a ping measurement has not yet been recorded.
	 */
	public function getPing() : ?int{
		return $this->ping;
	}

	/**
	 * @internal Called by the network interface to update last recorded ping measurements.
	 */
	public function updatePing(int $ping) : void{
		$this->ping = $ping;
	}

	public function getHandler() : ?PacketHandler{
		return $this->handler;
	}

	public function setHandler(?PacketHandler $handler) : void{
		if($this->connected){ //TODO: this is fine since we can't handle anything from a disconnected session, but it might produce surprises in some cases
			$this->connectionPhase = match(true){
				$handler instanceof SessionStartPacketHandler => "session_start",
				$handler instanceof LoginPacketHandler => "login",
				$handler instanceof HandshakePacketHandler => "handshake",
				$handler instanceof ResourcePacksPacketHandler => "resource_packs",
				$handler instanceof PreSpawnPacketHandler => "pre_spawn",
				$handler instanceof SpawnResponsePacketHandler => "spawn_response",
				$handler instanceof InGamePacketHandler => "in_game",
				$handler instanceof DeathPacketHandler => "death",
				$handler === null => "awaiting_async",
				default => "unknown"
			};
			$this->getPulseNetwork()?->updateSession($this->pulseSessionId, $this->protocolVersion, $this->connectionPhase);
			$this->handler = $handler;
			$this->handlerMethods = [];
			$this->legacyHandlers = [];
			if($this->handler !== null){
				[$this->handlerActions, $methods] = PacketHandlerInspector::inspect($this->handler);
				foreach($methods as $id => [$method, $takesFields]){
					$this->handlerMethods[$id] = (new \ReflectionMethod($this->handler, $method))->getClosure($this->handler);
					if(!$takesFields){
						$this->legacyHandlers[$id] = true;
					}
				}
				$this->handler->setUp();
			}else{
				$this->handlerActions = null;
			}
		}
	}

	private function checkRepeatedPacketFilter(string $buffer) : bool{
		if($buffer === $this->noisyPacketBuffer){
			$this->noisyPacketsDropped++;
			return true;
		}
		//stop filtering once we see a packet with a different buffer
		//this won't be any good for interleaved spammy packets, but we haven't seen any of those so far, and this
		//is the simplest and most conservative filter we can do
		$this->noisyPacketBuffer = "";
		$this->noisyPacketsDropped = 0;

		return false;
	}

	/**
	 * @throws PacketHandlingException
	 */
	public function handleEncoded(string $payload) : void{
		if(!$this->connected){
			return;
		}

		$network = $this->getPulseNetwork();
		$window = $network?->window($this->pulseSessionId);
		if($network !== null && $window !== null){
			$network->count($window, PulseNetwork::BATCHES);
			$network->count($window, PulseNetwork::RECEIVED_BYTES, strlen($payload));
		}
		$playerNetworkReceiveScope = PulseZones::$playerNetworkReceive->start();
		$work = $network?->work;
		$workToken = $work?->enter($this->pulseSessionId, $this->protocolVersion, $this->connectionPhase, null, Pulse::getNetworkTickId(), PulseNetworkWork::BATCH_DECODE) ?? 0;
		try{
			try{
				$this->packetBatchLimiter->decrement();
			}catch(PacketRateLimitException $e){
				$this->recordNetworkSecurityEvent("rate.batch", "reject_batch", observed: $e->requested, limit: $e->available);
				throw $e;
			}

			if($this->cipher !== null){
				$work?->stage($workToken, PulseNetworkWork::DECRYPT);
				$playerNetworkReceiveDecryptScope = PulseZones::$playerNetworkReceiveDecrypt->start();
				try{
					$payload = $this->cipher->decrypt($payload);
				}catch(DecryptionException $e){
					$this->recordNetworkSecurityEvent("encryption.invalid", "reject_batch");
					$this->logger->debug("Encrypted packet: " . Utils::printablePacketBuffer($payload));
					throw PacketHandlingException::wrap($e, "Packet decryption error");
				}finally{
					PulseZones::$playerNetworkReceiveDecrypt->stop($playerNetworkReceiveDecryptScope);
				}
			}

			if(strlen($payload) < 1){
				$this->recordNetworkSecurityEvent("batch.empty", "reject_batch", observed: 0, limit: 1);
				throw new PacketHandlingException("No bytes in payload");
			}

			if($this->enableCompression){
				$compressionTypeId = ord($payload[0]);
				$compressionType = CompressionAlgorithm::tryFrom($compressionTypeId);
				$compressed = substr($payload, 1);
				if($compressionType === CompressionAlgorithm::NONE){
					$decompressed = $compressed;
				}elseif($compressionType === $this->compressor->getNetworkType()){
					$work?->stage($workToken, PulseNetworkWork::DECOMPRESS);
					$playerNetworkReceiveDecompressScope = PulseZones::$playerNetworkReceiveDecompress->start();
					try{
						$decompressed = $this->compressor->decompress($compressed);
					}catch(DecompressionException $e){
						$this->recordNetworkSecurityEvent($e->reason, "reject_batch", observed: $e->observed, limit: $e->limit);
						$this->logger->debug("Failed to decompress packet: " . Utils::printablePacketBuffer($compressed));
						throw PacketHandlingException::wrap($e, "Compressed packet batch decode error");
					}finally{
						PulseZones::$playerNetworkReceiveDecompress->stop($playerNetworkReceiveDecompressScope);
					}
				}else{
					$this->recordNetworkSecurityEvent("compression.algorithm", "reject_batch", observed: $compressionTypeId, limit: $this->compressor->getNetworkType()->value);
					throw new PacketHandlingException("Packet compressed with unexpected compression type $compressionTypeId");
				}
			}else{
				$decompressed = $payload;
			}
			if($network !== null && $window !== null){ $network->count($window, PulseNetwork::DECOMPRESSED_BYTES, strlen($decompressed)); }

			$work?->stage($workToken, PulseNetworkWork::BATCH_DECODE);
			$this->handleBatchBuffers(PacketBatch::decodeRaw(new ByteBufferReader($decompressed)), $network, $window);
		}finally{
			$work?->leave($workToken);
			PulseZones::$playerNetworkReceive->stop($playerNetworkReceiveScope);
		}
	}

	/**
	 * Handles the packets of one batch in order.
	 *
	 * @param iterable<string> $buffers
	 * @throws PacketHandlingException
	 */
	private function handleBatchBuffers(iterable $buffers, ?PulseNetwork $network, ?int $window) : void{
		$count = 0;
		try{
			foreach($buffers as $buffer){
				if(++$count >= self::INCOMING_PACKET_BATCH_HARD_LIMIT){
					if($network !== null && $window !== null){ $network->count($window, PulseNetwork::PACKETS); }
					$this->recordRawPacketSecurityEvent($buffer, "batch.packet_limit", $count, self::INCOMING_PACKET_BATCH_HARD_LIMIT);
					throw new PacketHandlingException("Reached hard limit of " . self::INCOMING_PACKET_BATCH_HARD_LIMIT . " per batch packet");
				}

				if($this->checkRepeatedPacketFilter($buffer)){
					if($network !== null && $window !== null){
						$network->count($window, PulseNetwork::PACKETS);
						$network->count($window, PulseNetwork::REPEATED_DROPPED);
					}
					continue;
				}

				try{
					$this->gamePacketLimiter->decrement();
				}catch(PacketRateLimitException $e){
					if($network !== null && $window !== null){ $network->count($window, PulseNetwork::PACKETS); }
					$this->recordRawPacketSecurityEvent($buffer, "rate.packet", $e->requested, $e->available);
					throw $e;
				}
				try{
					$packetId = Protocol::packetId($buffer);
				}catch(PacketException $e){
					if($network !== null && $window !== null){
						$network->count($window, PulseNetwork::PACKETS);
						$network->count($window, PulseNetwork::DECODE_FAILED);
					}
					throw $e;
				}
				if(Protocol::name($packetId) === null){
					if($network !== null && $window !== null){
						$network->count($window, PulseNetwork::PACKETS);
						$network->count($window, PulseNetwork::DECODE_FAILED);
					}
					$this->recordRawPacketSecurityEvent($buffer, "packet.unknown");
					$this->logger->debug("Unknown packet: " . Utils::printablePacketBuffer($buffer));
					throw new PacketHandlingException("Unknown packet received");
				}
				try{
					$this->handleDataPacket($packetId, $buffer);
				}catch(PacketHandlingException $e){
					$this->unhandledPacketDebug($packetId, $buffer, "Packet processing error");
					throw PacketHandlingException::wrap($e, "Error processing " . Protocol::name($packetId));
				}catch(FilterNoisyPacketException){
					$this->noisyPacketBuffer = $buffer;
				}
				if(!$this->isConnected()){
					//handling this packet may have caused a disconnection
					$this->logger->debug("Aborting batch processing due to server-side disconnection");
					break;
				}
			}
		}catch(PacketException|PacketDecodeException|DataDecodeException $e){
			$this->recordNetworkSecurityEvent("batch.malformed", "reject_batch");
			$this->logger->logException($e);
			throw PacketHandlingException::wrap($e, "Packet batch decode error");
		}
	}

	/**
	 * Handles packets a native transport already decrypted, decompressed and split, in the order received.
	 *
	 * @param string[] $buffers
	 * @phpstan-param list<string> $buffers
	 * @throws PacketHandlingException
	 */
	public function handleDecodedPackets(array $buffers) : void{
		if(!$this->connected){
			return;
		}

		$network = $this->getPulseNetwork();
		$window = $network?->window($this->pulseSessionId);
		if($network !== null && $window !== null){
			$network->count($window, PulseNetwork::BATCHES);
		}
		$playerNetworkReceiveScope = PulseZones::$playerNetworkReceive->start();
		try{
			try{
				$this->packetBatchLimiter->decrement();
			}catch(PacketRateLimitException $e){
				$this->recordNetworkSecurityEvent("rate.batch", "reject_batch", observed: $e->requested, limit: $e->available);
				throw $e;
			}
			$this->handleBatchBuffers($buffers, $network, $window);
		}finally{
			PulseZones::$playerNetworkReceive->stop($playerNetworkReceiveScope);
		}
	}

	private function unhandledPacketDebug(int $packetId, string $buffer, string $label) : void{
		$this->logger->debug($label . ": " . Protocol::name($packetId) . " (" . Utils::printablePacketBuffer($buffer) . ")");
	}

	/**
	 * @throws PacketHandlingException
	 * @throws FilterNoisyPacketException
	 */
	public function handleDataPacket(int $packetId, string $buffer) : void{
		$network = $this->getPulseNetwork();
		$window = $network?->window($this->pulseSessionId);
		if($network !== null && $window !== null){ $network->count($window, PulseNetwork::PACKETS); }
		if(!isset(Packets::HANDLERS[$packetId])){
			$this->recordNetworkSecurityEvent("packet.direction", "reject_batch", $packetId);
			throw new PacketHandlingException("Unexpected non-serverbound packet");
		}

		$pulse = PulseZones::getReceiveDataPacketZone($packetId);
		$scope = $pulse->start();
		$work = $network?->work;
		$workToken = 0;

		try{
			$handlerAction = $this->handlerActions[$packetId] ?? PacketHandlerAction::DISCARD_WITH_DEBUG;
			$hasDecodeHandlers = DataPacketDecodeEvent::hasHandlers();
			$workToken = $work?->enter($this->pulseSessionId, $this->protocolVersion, $this->connectionPhase, $packetId, Pulse::getNetworkTickId(), $hasDecodeHandlers ? PulseNetworkWork::DECODE_EVENT : ($handlerAction === PacketHandlerAction::HANDLED ? PulseNetworkWork::DECODE : PulseNetworkWork::PACKET_STATE)) ?? 0;
			if($hasDecodeHandlers){
				$ev = new DataPacketDecodeEvent($this, $packetId, $buffer);
				$cancel = $handlerAction !== PacketHandlerAction::HANDLED;
				if($cancel){
					$ev->cancel();
				}
				$ev->call();
				if($cancel && !$ev->isCancelled()){
					//uncancelled by a plugin, let it through to DataPacketReceiveEvent
					$handlerAction = PacketHandlerAction::HANDLED;
				}elseif(!$cancel && $ev->isCancelled()){
					if($network !== null && $window !== null){ $network->count($window, PulseNetwork::PLUGIN_CANCELLED); }
					//explicitly cancelled by plugin, drop it quietly
					$handlerAction = PacketHandlerAction::DISCARD_SILENT;
				}
			}

			if($handlerAction !== PacketHandlerAction::HANDLED){
				if($handlerAction === PacketHandlerAction::DISCARD_WITH_DEBUG){
					if($network !== null && $window !== null){ $network->count($window, PulseNetwork::STATE_DROPPED); }
					$this->recordNetworkSecurityEvent("packet.state", "drop_packet", $packetId);
					$this->unhandledPacketDebug($packetId, $buffer, "Discarded without decoding");
				}
				return;
			}

			$legacy = isset($this->legacyHandlers[$packetId]);
			$decodeZone = PulseZones::getDecodeDataPacketZone($packetId);
			$decodeZoneScope = $decodeZone->start();
			try{
				$work?->stage($workToken, PulseNetworkWork::DECODE);
				$legacyPacket = null;
				try{
					if($legacy){
						$this->checkLegacyPacketLimits($packetId, $buffer, $network, $window);
						$packet = Packet::fromEncoded($buffer);
						$legacyPacket = LegacyPacketAdapter::toLegacy($packet, $unread);
						if($unread > 0){
							$this->logger->debug("Unread bytes in " . $packet->getName() . ": " . Utils::printablePacketBuffer($buffer, strlen($buffer) - $unread));
						}
					}else{
						$packet = Packet::decoded($packetId, $buffer, Protocol::decode($buffer, self::MAX_DECODED_VALUES) ?? throw new AssumptionFailedError("Known packet IDs always decode"));
					}
					if($network !== null && $window !== null){ $network->count($window, PulseNetwork::DECODED); }
				}catch(PacketException|PacketDecodeException|DataDecodeException|\InvalidArgumentException $e){
					//PlayerAuthInput's BitSet throws argument errors for invalid flag indices.
					if($e instanceof \InvalidArgumentException && $packetId !== PacketId::PLAYER_AUTH_INPUT){
						throw $e;
					}
					if($network !== null && $window !== null){ $network->count($window, PulseNetwork::DECODE_FAILED); }
					if($e instanceof PacketDecodeException && $e->observed !== null && $e->limit !== null){
						$this->recordNetworkSecurityEvent("packet.handler_validation", "reject_batch", $packetId, $e->observed, $e->limit);
					}else{
						$this->recordNetworkSecurityEvent("packet.malformed", "reject_batch", $packetId);
					}
					throw PacketHandlingException::wrap($e);
				}
			}finally{
				$decodeZone->stop($decodeZoneScope);
			}

			if(DataPacketReceiveEvent::hasHandlers()){
				$work?->stage($workToken, PulseNetworkWork::RECEIVE_EVENT);
				$ev = new DataPacketReceiveEvent($this, $packet);
				$ev->call();
				if($ev->isCancelled()){
					if($network !== null && $window !== null){ $network->count($window, PulseNetwork::PLUGIN_CANCELLED); }
					return;
				}
				if(!$packet->hasEncoded()){
					//fields a plugin replaced are only handled once they encode and decode like a received packet would
					$encoded = $packet->getEncoded();
					if($legacy){
						$legacyPacket = LegacyPacketAdapter::toLegacy($packet);
					}else{
						$packet = Packet::decoded($packetId, $encoded, Protocol::decode($encoded) ?? throw new AssumptionFailedError("Known packet IDs always decode"));
					}
				}
			}
			$handlerZone = PulseZones::getHandleDataPacketZone($packetId);
			$handlerZoneScope = $handlerZone->start();
			try{
				$work?->stage($workToken, PulseNetworkWork::HANDLE);
				$method = $this->handlerMethods[$packetId] ?? null;
				if($method === null || !$method($legacyPacket ?? $packet->getFields())){
					if($network !== null && $window !== null){ $network->count($window, PulseNetwork::STATE_DROPPED); }
					$this->recordNetworkSecurityEvent("packet.state", "drop_packet", $packetId);
					$this->unhandledPacketDebug($packetId, $buffer, "Handler rejected");
				}
			}catch(PacketHandlingException $e){
				$this->recordNetworkSecurityEvent("packet.handler_validation", "reject_batch", $packetId);
				throw $e;
			}finally{
				$handlerZone->stop($handlerZoneScope);
			}
		}finally{
			$work?->leave($workToken);
			$pulse->stop($scope);
		}
	}

	/**
	 * Rejects oversized collections before BedrockProtocol decodes them. Packets with native handlers rely on the value
	 * limit of Bedwire\Protocol::decode() instead.
	 *
	 * @throws PacketHandlingException
	 * @throws PacketDecodeException
	 * @throws DataDecodeException
	 */
	private function checkLegacyPacketLimits(int $packetId, string $buffer, ?PulseNetwork $network, ?int $window) : void{
		if($packetId !== PacketId::ITEM_STACK_REQUEST && $packetId !== PacketId::PLAYER_AUTH_INPUT && $packetId !== PacketId::INVENTORY_TRANSACTION){
			return;
		}
		$stream = new ByteBufferReader($buffer);
		if((VarInt::readUnsignedInt($stream) & DataPacket::PID_MASK) !== $packetId){
			throw new PacketDecodeException("Unexpected packet ID");
		}
		if($packetId === PacketId::PLAYER_AUTH_INPUT){
			//Eight floats precede the input flag list.
			if($stream->getUnreadLength() < 8 * 4){
				throw new PacketDecodeException("Truncated PlayerAuthInputPacket");
			}
			$stream->setOffset($stream->getOffset() + 8 * 4);
		}elseif($packetId === PacketId::INVENTORY_TRANSACTION){
			CommonTypes::readLegacyItemStackRequestId($stream);
			if(CommonTypes::getBool($stream)){
				$count = VarInt::readUnsignedInt($stream);
				if($count > InGamePacketHandler::MAX_INVENTORY_SLOT_SYNC_REQUESTS){
					if($network !== null && $window !== null){ $network->count($window, PulseNetwork::DECODE_FAILED); }
					$this->recordNetworkSecurityEvent("packet.handler_validation", "reject_batch", $packetId, $count, InGamePacketHandler::MAX_INVENTORY_SLOT_SYNC_REQUESTS);
					throw new PacketHandlingException("Too many slot sync requests in inventory transaction");
				}
				for($i = 0; $i < $count; ++$i){
					Byte::readUnsigned($stream);
					$slots = VarInt::readUnsignedInt($stream);
					if($slots > $stream->getUnreadLength()){
						throw new PacketDecodeException("Truncated inventory slot sync request");
					}
					//Slot indexes are bytes; skip them without allocating a list.
					$stream->setOffset($stream->getOffset() + $slots);
				}
			}
			$type = VarInt::readUnsignedInt($stream);
			if($type > InventoryTransactionPacket::TYPE_RELEASE_ITEM){
				throw new PacketDecodeException("Unknown transaction type $type");
			}
		}
		$count = VarInt::readUnsignedInt($stream);
		$limit = match($packetId){
			PacketId::PLAYER_AUTH_INPUT => PlayerAuthInputFlags::NUMBER_OF_FLAGS,
			PacketId::ITEM_STACK_REQUEST => InGamePacketHandler::MAX_ITEM_STACK_REQUESTS,
			default => InGamePacketHandler::MAX_INVENTORY_TRANSACTION_ACTIONS
		};
		if($count > $limit){
			if($network !== null && $window !== null){ $network->count($window, PulseNetwork::DECODE_FAILED); }
			$this->recordNetworkSecurityEvent("packet.handler_validation", "reject_batch", $packetId, $count, $limit);
			throw new PacketHandlingException(match($packetId){
				PacketId::ITEM_STACK_REQUEST => "Too many requests in ItemStackRequestPacket",
				PacketId::INVENTORY_TRANSACTION => "Too many actions in inventory transaction",
				default => "Too many input flags in PlayerAuthInputPacket"
			});
		}
	}

	public function handleAckReceipt(int $receiptId) : void{
		if(!$this->connected){
			return;
		}
		if(isset($this->ackPromisesByReceiptId[$receiptId])){
			$promises = $this->ackPromisesByReceiptId[$receiptId];
			unset($this->ackPromisesByReceiptId[$receiptId]);
			foreach($promises as $promise){
				$promise->resolve(true);
			}
		}
	}

	/**
	 * @phpstan-param PromiseResolver<true>|null $ackReceiptResolver
	 */
	private function sendDataPacketInternal(Packet|ClientboundPacket $packet, bool $immediate, ?PromiseResolver $ackReceiptResolver) : bool{
		if(!$this->connected){
			return false;
		}
		if($packet instanceof ClientboundPacket){
			$packet = LegacyPacketAdapter::fromLegacy($packet);
		}
		$packetId = $packet->getId();
		if(!isset(Packets::CLIENTBOUND[$packetId])){
			throw new \InvalidArgumentException("Clients do not accept " . $packet->getName() . " packets");
		}
		//Basic safety restriction. TODO: improve this
		if(!$this->loggedIn && !isset(self::PRE_LOGIN_PACKETS[$packetId])){
			throw new \InvalidArgumentException("Attempted to send " . $packet->getName() . " to " . $this->getDisplayName() . " too early");
		}

		$pulse = PulseZones::getSendDataPacketZone($packetId);
		$scope = $pulse->start();
		try{
			if(DataPacketSendEvent::hasHandlers()){
				$ev = new DataPacketSendEvent([$this], [$packet]);
				$ev->call();
				if($ev->isCancelled()){
					return false;
				}
				$packets = $ev->getPackets();
			}else{
				$packets = [$packet];
			}

			if($ackReceiptResolver !== null){
				$this->sendBufferAckPromises[] = $ackReceiptResolver;
			}
			foreach($packets as $evPacket){
				$this->sendBuffer[] = $evPacket->toBatchEntry();
			}
			if($immediate){
				$this->flushGamePacketQueue();
			}

			return true;
		}finally{
			$pulse->stop($scope);
		}
	}

	/**
	 * Queues a packet, which is encoded with the rest of the session's packets when they are flushed.
	 */
	public function sendDataPacket(Packet|ClientboundPacket $packet, bool $immediate = false) : bool{
		return $this->sendDataPacketInternal($packet, $immediate, null);
	}

	/**
	 * @phpstan-return Promise<true>
	 */
	public function sendDataPacketWithReceipt(Packet|ClientboundPacket $packet, bool $immediate = false) : Promise{
		/** @phpstan-var PromiseResolver<true> $resolver */
		$resolver = new PromiseResolver();

		if(!$this->sendDataPacketInternal($packet, $immediate, $resolver)){
			$resolver->reject();
		}

		return $resolver->getPromise();
	}

	/**
	 * @internal Queues a packet the broadcaster already checked and fired events for.
	 */
	public function addToSendBuffer(Packet $packet) : void{
		$this->sendBuffer[] = $packet->toBatchEntry();
	}

	/**
	 * Encodes queued packets into one batch body with a single native call.
	 *
	 * @phpstan-param list<string|array{int, array<string, mixed>}> $packets
	 * @throws \ValueError naming the first packet whose fields do not fit it
	 */
	public static function encodeBatch(array $packets) : string{
		$scope = PulseZones::$playerNetworkSendEncode->start();
		try{
			return Protocol::encodeBatch($packets);
		}catch(\ValueError|\TypeError $e){
			//find the culprit, so a bad packet is reported where it was built instead of as an anonymous batch
			foreach($packets as $packet){
				if(is_array($packet)){
					try{
						Protocol::encode($packet[0], $packet[1]);
					}catch(\ValueError|\TypeError $inner){
						throw new \ValueError("Invalid " . Protocol::name($packet[0]) . " packet: " . $inner->getMessage(), 0, $inner);
					}
				}
			}
			throw $e;
		}finally{
			PulseZones::$playerNetworkSendEncode->stop($scope);
		}
	}

	private function flushGamePacketQueue() : void{
		if(count($this->sendBuffer) > 0){
			$playerNetworkSendScope = PulseZones::$playerNetworkSend->start();
			try{
				$syncMode = null; //automatic
				if($this->forceAsyncCompression){
					$syncMode = false;
				}

				$packets = $this->sendBuffer;
				$this->sendBuffer = [];
				$encoded = self::encodeBatch($packets);

				if($this->enableCompression){
					$batch = $this->server->prepareBatch($encoded, $this->compressor, $syncMode, PulseZones::$playerNetworkSendCompressSessionBuffer);
				}else{
					$batch = $encoded;
				}
				$ackPromises = $this->sendBufferAckPromises;
				$this->sendBufferAckPromises = [];
				//these packets were already potentially buffered for up to 50ms - make sure the transport layer doesn't
				//delay them any longer
				$this->queueCompressedNoGamePacketFlush($batch, networkFlush: true, ackPromises: $ackPromises);
			}finally{
				PulseZones::$playerNetworkSend->stop($playerNetworkSendScope);
			}
		}
	}

	/** Flushes latency sensitive packets in order, at most once per server tick. */
	public function flushLatencySensitiveOutput(bool $synchronizePlayerAttributes = false) : void{
		if(!$this->latencySensitiveFlushEnabled || $this->latencySensitiveFlushPerformedThisTick || !$this->connected){
			return;
		}

		if($synchronizePlayerAttributes){
			$this->synchronizePlayerAttributes();
		}
		if(count($this->sendBuffer) === 0){
			return;
		}

		$this->latencySensitiveFlushPerformedThisTick = true;
		$this->flushGamePacketQueue();
	}

	private function synchronizePlayerAttributes() : void{
		if($this->player === null){
			return;
		}

		$dirtyAttributes = $this->player->getAttributeMap()->needSend();
		$this->entityEventBroadcaster->syncAttributes([$this], $this->player, $dirtyAttributes);
		foreach($dirtyAttributes as $attribute){
			$attribute->markSynchronized();
		}
	}

	public function getBroadcaster() : PacketBroadcaster{ return $this->broadcaster; }

	public function getEntityEventBroadcaster() : EntityEventBroadcaster{ return $this->entityEventBroadcaster; }

	public function getCompressor() : Compressor{
		return $this->compressor;
	}

	public function getTypeConverter() : TypeConverter{ return $this->typeConverter; }

	public function queueCompressed(CompressBatchPromise|string $payload, bool $immediate = false) : void{
		$playerNetworkSendScope = PulseZones::$playerNetworkSend->start();
		try{
			//if the next packet causes a flush, avoid unnecessarily flushing twice
			//however, if the next packet does *not* cause a flush, game packets should be flushed to avoid delays
			$this->flushGamePacketQueue();
			$this->queueCompressedNoGamePacketFlush($payload, $immediate);
		}finally{
			PulseZones::$playerNetworkSend->stop($playerNetworkSendScope);
		}
	}

	/**
	 * @param PromiseResolver[] $ackPromises
	 *
	 * @phpstan-param list<PromiseResolver<true>> $ackPromises
	 */
	private function queueCompressedNoGamePacketFlush(CompressBatchPromise|string $batch, bool $networkFlush = false, array $ackPromises = []) : void{
		$playerNetworkSendScope = PulseZones::$playerNetworkSend->start();
		try{
			$this->compressedQueue->enqueue([$batch, $ackPromises, $networkFlush]);
			if(is_string($batch)){
				$this->flushCompressedQueue();
			}else{
				$batch->onResolve(function() : void{
					if($this->connected){
						$this->flushCompressedQueue();
					}
				});
			}
		}finally{
			PulseZones::$playerNetworkSend->stop($playerNetworkSendScope);
		}
	}

	private function flushCompressedQueue() : void{
		$playerNetworkSendScope = PulseZones::$playerNetworkSend->start();
		try{
			while(!$this->compressedQueue->isEmpty()){
				/** @var CompressBatchPromise|string $current */
				[$current, $ackPromises, $networkFlush] = $this->compressedQueue->bottom();
				if(is_string($current)){
					$this->compressedQueue->dequeue();
					$this->sendEncoded($current, $networkFlush, $ackPromises);

				}elseif($current->hasResult()){
					$this->compressedQueue->dequeue();
					$this->sendEncoded($current->getResult(), $networkFlush, $ackPromises);

				}else{
					//can't send any more queued until this one is ready
					break;
				}
			}
		}finally{
			PulseZones::$playerNetworkSend->stop($playerNetworkSendScope);
		}
	}

	/**
	 * @param PromiseResolver[] $ackPromises
	 * @phpstan-param list<PromiseResolver<true>> $ackPromises
	 */
	private function sendEncoded(string $payload, bool $immediate, array $ackPromises) : void{
		if($this->sender instanceof NativeBatchPacketSender){
			//once compression is on, batches carry an algorithm byte, which a NativeCompressor leaves as NONE
			if($this->enableCompression && ord($payload[0]) !== CompressionAlgorithm::NONE->value){
				throw new AssumptionFailedError("Native transports must be given uncompressed batches");
			}
			$this->sender->sendBatch($payload, $this->enableCompression ? 1 : 0);
			//NetherNet's reliable channel has no per-batch acknowledgements, so queueing is as far as we can follow
			foreach($ackPromises as $promise){
				$promise->resolve(true);
			}
			return;
		}
		if($this->cipher !== null){
			$playerNetworkSendEncryptScope = PulseZones::$playerNetworkSendEncrypt->start();
			$payload = $this->cipher->encrypt($payload);
			PulseZones::$playerNetworkSendEncrypt->stop($playerNetworkSendEncryptScope);
		}

		if(count($ackPromises) > 0){
			$ackReceiptId = $this->nextAckReceiptId++;
			$this->ackPromisesByReceiptId[$ackReceiptId] = $ackPromises;
		}else{
			$ackReceiptId = null;
		}
		$this->sender->send($payload, $immediate, $ackReceiptId);
	}

	/**
	 * @phpstan-param \Closure() : void $func
	 */
	private function tryDisconnect(\Closure $func, Translatable|string $reason) : void{
		if($this->connected && !$this->disconnectGuard){
			$this->disconnectGuard = true;
			$func();
			$this->disconnectGuard = false;
			$this->flushGamePacketQueue();
			$this->sender->close("");
			foreach($this->disposeHooks as $callback){
				$callback();
			}
			$this->disposeHooks->clear();
			$this->setHandler(null);
			$this->connected = false;
			$this->connectionPhase = "closed";
			$this->getPulseNetwork()?->updateSession($this->pulseSessionId, $this->protocolVersion, $this->connectionPhase);

			$ackPromisesByReceiptId = $this->ackPromisesByReceiptId;
			$this->ackPromisesByReceiptId = [];
			foreach($ackPromisesByReceiptId as $resolvers){
				foreach($resolvers as $resolver){
					$resolver->reject();
				}
			}
			$sendBufferAckPromises = $this->sendBufferAckPromises;
			$this->sendBufferAckPromises = [];
			foreach($sendBufferAckPromises as $resolver){
				$resolver->reject();
			}

			$this->logger->info($this->server->getLanguage()->translate(KnownTranslationFactory::quark_network_session_close($reason)));
		}
	}

	/**
	 * Performs actions after the session has been disconnected. By this point, nothing should be interacting with the
	 * session, so it's safe to destroy any cycles and perform destructive cleanup.
	 */
	private function dispose() : void{
		$this->invManager = null;
	}

	private function sendDisconnectPacket(Translatable|string $message) : void{
		if($message instanceof Translatable){
			$translated = $this->server->getLanguage()->translate($message);
		}else{
			$translated = $message;
		}
		$this->sendDataPacket(Packets::disconnect(["reason" => 0, "messages" => ["messages" => ["message" => $translated, "filtered_message" => ""]]]));
	}

	/**
	 * Disconnects the session, destroying the associated player (if it exists).
	 *
	 * @param Translatable|string      $reason                  Shown in the server log - this should be a short one-line message
	 * @param Translatable|string|null $disconnectScreenMessage Shown on the player's disconnection screen (null will use the reason)
	 */
	public function disconnect(Translatable|string $reason, Translatable|string|null $disconnectScreenMessage = null, bool $notify = true) : void{
		$this->tryDisconnect(function() use ($reason, $disconnectScreenMessage, $notify) : void{
			if($notify){
				$this->sendDisconnectPacket($disconnectScreenMessage ?? $reason);
			}
			if($this->player !== null){
				$this->player->onPostDisconnect($reason, null);
			}
		}, $reason);
	}

	public function disconnectWithError(Translatable|string $reason, Translatable|string|null $disconnectScreenMessage = null) : void{
		$errorId = implode("-", str_split(bin2hex(random_bytes(6)), 4));

		$this->disconnect(
			reason: KnownTranslationFactory::quark_disconnect_error($reason, $errorId)->prefix(TextFormat::RED),
			disconnectScreenMessage: KnownTranslationFactory::quark_disconnect_error($disconnectScreenMessage ?? $reason, $errorId),
		);
	}

	public function disconnectIncompatibleProtocol(int $protocolVersion) : void{
		$this->tryDisconnect(
			function() use ($protocolVersion) : void{
				$this->sendDataPacket(Packets::playStatus(["status" => $protocolVersion < Protocol::VERSION ? PlayStatusPacket::LOGIN_FAILED_CLIENT : PlayStatusPacket::LOGIN_FAILED_SERVER]), true);
			},
			KnownTranslationFactory::quark_disconnect_incompatibleProtocol((string) $protocolVersion)
		);
	}

	/**
	 * Instructs the remote client to connect to a different server.
	 */
	public function transfer(string $ip, int $port, Translatable|string|null $reason = null) : void{
		$reason ??= KnownTranslationFactory::quark_disconnect_transfer();
		$this->tryDisconnect(function() use ($ip, $port, $reason) : void{
			$this->sendDataPacket(Packets::transfer(["server_address" => $ip, "server_port" => $port, "reload_world" => false, "gatherings_configuration" => null]), true);
			if($this->player !== null){
				$this->player->onPostDisconnect($reason, null);
			}
		}, $reason);
	}

	/**
	 * Called by the Player when it is closed (for example due to getting kicked).
	 */
	public function onPlayerDestroyed(Translatable|string $reason, Translatable|string $disconnectScreenMessage) : void{
		$this->tryDisconnect(function() use ($disconnectScreenMessage) : void{
			$this->sendDisconnectPacket($disconnectScreenMessage);
		}, $reason);
	}

	/**
	 * Called by the network interface to close the session when the client disconnects without server input, for
	 * example in a timeout condition or voluntary client disconnect.
	 */
	public function onClientDisconnect(Translatable|string $reason) : void{
		$this->tryDisconnect(function() use ($reason) : void{
			if($this->player !== null){
				$this->player->onPostDisconnect($reason, null);
			}
		}, $reason);
	}

	private function setAuthenticationStatus(bool $authenticated, bool $authRequired, Translatable|string|null $error, ?string $clientPubKey) : void{
		if(!$this->connected){
			return;
		}
		if($error === null){
			if($authenticated && !($this->info instanceof XboxLivePlayerInfo)){
				$error = "Expected XUID but none found";
			}elseif($clientPubKey === null){
				$error = "Missing client public key"; //failsafe
			}elseif($authenticated && $this->transportIdentityKey !== null && !$this->server->isTrustedProxy($this->transportIdentityKey)){
				//a trusted proxy already bound the player's login to their own connection
				try{
					$this->transportIdentityKey->verify($clientPubKey);
				}catch(TransportIdentityException $e){
					$error = $e->getMessage();
				}
			}
		}

		if($error !== null){
			$this->disconnectWithError(
				reason: KnownTranslationFactory::quark_disconnect_invalidSession($error),
				disconnectScreenMessage: KnownTranslationFactory::quark_disconnect_error_authentication()
			);

			return;
		}
		if($clientPubKey === null){
			throw new AssumptionFailedError("Client public key should have been checked above");
		}

		$this->authenticated = $authenticated;

		if(!$this->authenticated){
			if($authRequired){
				$this->disconnect("Not authenticated", KnownTranslationFactory::disconnectionScreen_notAuthenticated());
				return;
			}
			if($this->info instanceof XboxLivePlayerInfo){
				$this->logger->warning("Discarding unexpected XUID for non-authenticated player");
				$this->info = $this->info->withoutXboxData();
			}
		}
		$this->logger->debug("Xbox Live authenticated: " . ($this->authenticated ? "YES" : "NO"));
		$ev = new PlayerIdentityVerifyEvent(
			$this,
			$this->info ?? throw new AssumptionFailedError("Player info is set before login verification starts"),
			$this->authenticated,
			$authRequired,
			$clientPubKey,
			$this->transportIdentityKey,
			"Plugin reason",
			KnownTranslationFactory::quark_disconnect_error_authentication()
		);
		$ev->call();
		if($ev->isCancelled()){
			$this->disconnect($ev->getDisconnectReason(), $ev->getDisconnectScreenMessage());
			return;
		}

		$checkXUID = $this->server->getConfigGroup()->getPropertyBool(YmlServerProperties::PLAYER_VERIFY_XUID, true);
		$myXUID = $this->info instanceof XboxLivePlayerInfo ? $this->info->getXuid() : "";
		$kickForXUIDMismatch = function(string $xuid) use ($checkXUID, $myXUID) : bool{
			if($checkXUID && $myXUID !== $xuid){
				$this->logger->debug("XUID mismatch: expected '$xuid', but got '$myXUID'");
				//TODO: Longer term, we should be identifying playerdata using something more reliable, like XUID or UUID.
				//However, that would be a very disruptive change, so this will serve as a stopgap for now.
				//Side note: this will also prevent offline players hijacking XBL playerdata on online servers, since their
				//XUID will always be empty.
				$this->disconnect("XUID does not match (possible impersonation attempt)");
				return true;
			}
			return false;
		};

		foreach($this->manager->getSessions() as $existingSession){
			if($existingSession === $this){
				continue;
			}
			$info = $existingSession->getPlayerInfo();
			if($info !== null && (strcasecmp($info->getUsername(), $this->info->getUsername()) === 0 || $info->getUuid()->equals($this->info->getUuid()))){
				if($kickForXUIDMismatch($info instanceof XboxLivePlayerInfo ? $info->getXuid() : "")){
					return;
				}
				$ev = new PlayerDuplicateLoginEvent($this, $existingSession, KnownTranslationFactory::disconnectionScreen_loggedinOtherLocation(), null);
				$ev->call();
				if($ev->isCancelled()){
					$this->disconnect($ev->getDisconnectReason(), $ev->getDisconnectScreenMessage());
					return;
				}

				$existingSession->disconnect($ev->getDisconnectReason(), $ev->getDisconnectScreenMessage());
			}
		}

		//TODO: make player data loading async
		//TODO: we shouldn't be loading player data here at all, but right now we don't have any choice :(
		$this->cachedOfflinePlayerData = $this->server->getOfflinePlayerData($this->info->getUsername());
		if($checkXUID){
			$recordedXUID = $this->cachedOfflinePlayerData !== null ? $this->cachedOfflinePlayerData->getTag(Player::TAG_LAST_KNOWN_XUID) : null;
			if(!($recordedXUID instanceof StringTag)){
				$this->logger->debug("No previous XUID recorded, no choice but to trust this player");
			}elseif(!$kickForXUIDMismatch($recordedXUID->getValue())){
				$this->logger->debug("XUID match");
			}
		}

		if($this->sender instanceof NativeBatchPacketSender){
			$this->sender->acceptLogin($clientPubKey);
		}

		if(EncryptionContext::$ENABLED && !($this->transportEncrypted && $this->transportIdentityKey !== null)){
			$this->server->getAsyncPool()->submitTask(new PrepareEncryptionTask($clientPubKey, function(string $encryptionKey, string $handshakeJwt) : void{
				if(!$this->connected){
					return;
				}
				$this->sendDataPacket(Packets::serverToClientHandshake(["handshake_web_token" => $handshakeJwt]), true); //make sure this gets sent before encryption is enabled

				$this->cipher = EncryptionContext::fakeGCM($encryptionKey);

				$this->setHandler(new HandshakePacketHandler($this->onServerLoginSuccess(...)));
				$this->logger->debug("Enabled encryption");
			}));
		}else{
			$this->onServerLoginSuccess();
		}
	}

	private function onServerLoginSuccess() : void{
		$this->loggedIn = true;

		$this->sendDataPacket(Packets::playStatus(["status" => PlayStatusPacket::LOGIN_SUCCESS]));

		$this->logger->debug("Initiating resource packs phase");

		$packManager = $this->server->getResourcePackManager();
		$resourcePacks = $packManager->getResourceStack();
		$keys = [];
		foreach($resourcePacks as $resourcePack){
			$key = $packManager->getPackEncryptionKey($resourcePack->getPackId());
			if($key !== null){
				$keys[$resourcePack->getPackId()] = $key;
			}
		}
		$event = new PlayerResourcePackOfferEvent($this->info, $resourcePacks, $keys, $packManager->resourcePacksRequired());
		$event->call();
		$this->setHandler(new ResourcePacksPacketHandler($this, $event->getResourcePacks(), $event->getEncryptionKeys(), $event->mustAccept(), function() : void{
			$this->createPlayer();
		}));
	}

	private function beginSpawnSequence() : void{
		$this->setHandler(new PreSpawnPacketHandler($this->server, $this->player, $this, $this->invManager));
		$this->player->setNoClientPredictions(); //TODO: HACK: fix client-side falling pre-spawn

		$this->logger->debug("Waiting for chunk radius request");
	}

	public function notifyTerrainReady() : void{
		$this->logger->debug("Sending spawn notification, waiting for spawn response");
		$this->sendDataPacket(Packets::playStatus(["status" => PlayStatusPacket::PLAYER_SPAWN]));
		$this->setHandler(new SpawnResponsePacketHandler($this->onClientSpawnResponse(...)));
	}

	private function onClientSpawnResponse() : void{
		$this->logger->debug("Received spawn response, entering in-game phase");
		$this->player->setNoClientPredictions(false); //TODO: HACK: we set this during the spawn sequence to prevent the client sending junk movements
		$this->player->doFirstSpawn();
		$this->forceAsyncCompression = false;
		$this->setHandler(new InGamePacketHandler($this->player, $this, $this->invManager));
	}

	public function onServerDeath(Translatable|string $deathMessage) : void{
		if($this->handler instanceof InGamePacketHandler){ //TODO: this is a bad fix for pre-spawn death, this shouldn't be reachable at all at this stage :(
			$this->setHandler(new DeathPacketHandler($this->player, $this, $this->invManager ?? throw new AssumptionFailedError(), $deathMessage));
		}
	}

	public function onServerRespawn() : void{
		$this->entityEventBroadcaster->syncAttributes([$this], $this->player, $this->player->getAttributeMap()->getAll());
		$this->player->sendData(null);

		$this->syncAbilities($this->player);
		$this->invManager->syncAll();
		$this->setHandler(new InGamePacketHandler($this->player, $this, $this->invManager));
	}

	public function syncMovement(Vector3 $pos, ?float $yaw = null, ?float $pitch = null, int $mode = MovePlayerPacket::MODE_NORMAL) : void{
		if($this->player !== null){
			$location = $this->player->getLocation();
			$yaw = $yaw ?? $location->getYaw();
			$pitch = $pitch ?? $location->getPitch();

			$this->sendDataPacket(Packets::movePlayer([
				"player_runtime_id" => $this->player->getId(),
				"position" => Fields::vec3($this->player->getOffsetPosition($pos)),
				"rotation" => Fields::vec2($pitch, $yaw),
				"y_head_rotation" => $yaw, //TODO: head yaw
				"position_mode" => $mode,
				"on_ground" => $this->player->onGround,
				"riding_runtime_id" => 0, //TODO: riding entity ID
				"teleport_data" => $mode === MovePlayerPacket::MODE_TELEPORT ? ["teleportation_cause" => 0, "source_actor_type" => 0] : null,
				"tick" => 0, //TODO: tick
			]));

			if($this->handler instanceof InGamePacketHandler){
				$this->handler->forceMoveSync = true;
			}
		}
	}

	public function syncViewAreaRadius(int $distance) : void{
		$this->sendDataPacket(Packets::chunkRadiusUpdated(["chunk_radius" => $distance]));
	}

	public function syncViewAreaCenterPoint(Vector3 $newPos, int $viewDistance) : void{
		$this->sendDataPacket(Packets::networkChunkPublisherUpdate([
			"new_position_for_view" => Fields::blockPosition($newPos),
			"new_radius_for_view" => $viewDistance * 16, //blocks, not chunks >.>
			"server_built_chunks_list" => [],
		]));
	}

	public function syncPlayerSpawnPoint(Position $newSpawn) : void{
		$newSpawnBlockPosition = Fields::blockPosition($newSpawn);
		//TODO: respawn causing block position (bed, respawn anchor)
		$this->sendDataPacket(Packets::setSpawnPosition([
			"spawn_position_type" => SetSpawnPositionPacket::TYPE_PLAYER_SPAWN,
			"block_position" => $newSpawnBlockPosition,
			"dimension_type" => DimensionIds::OVERWORLD,
			"spawn_block_pos" => $newSpawnBlockPosition,
		]));
	}

	public function syncWorldSpawnPoint(Position $newSpawn) : void{
		$this->sendDataPacket(Packets::setSpawnPosition([
			"spawn_position_type" => SetSpawnPositionPacket::TYPE_WORLD_SPAWN,
			"block_position" => Fields::blockPosition($newSpawn),
			"dimension_type" => DimensionIds::OVERWORLD,
			"spawn_block_pos" => ["x" => Limits::INT32_MIN, "y" => Limits::INT32_MIN, "z" => Limits::INT32_MIN],
		]));
	}

	public function syncGameMode(GameMode $mode, bool $isRollback = false) : void{
		$this->sendDataPacket(Packets::setPlayerGameType(["player_game_type" => $this->typeConverter->coreGameModeToProtocol($mode)->value]));
		if($this->player !== null){
			$this->syncAbilities($this->player);
			$this->syncAdventureSettings(); //TODO: we might be able to do this with the abilities packet alone
		}
		if(!$isRollback && $this->invManager !== null){
			$this->invManager->syncCreative();
		}
	}

	public function syncAbilities(Player $for) : void{
		$isOp = $for->hasPermission(DefaultPermissions::ROOT_OPERATOR);

		//ALL of these need to be set for the base layer, otherwise the client will cry
		$boolAbilities = [
			AbilitiesLayer::ABILITY_ALLOW_FLIGHT => $for->getAllowFlight(),
			AbilitiesLayer::ABILITY_FLYING => $for->isFlying(),
			AbilitiesLayer::ABILITY_NO_CLIP => !$for->hasBlockCollision(),
			AbilitiesLayer::ABILITY_OPERATOR => $isOp,
			AbilitiesLayer::ABILITY_TELEPORT => $for->hasPermission(DefaultPermissionNames::COMMAND_TELEPORT_SELF),
			AbilitiesLayer::ABILITY_INVULNERABLE => $for->isCreative(),
			AbilitiesLayer::ABILITY_MUTED => false,
			AbilitiesLayer::ABILITY_WORLD_BUILDER => false,
			AbilitiesLayer::ABILITY_INFINITE_RESOURCES => !$for->hasFiniteResources(),
			AbilitiesLayer::ABILITY_LIGHTNING => false,
			AbilitiesLayer::ABILITY_BUILD => !$for->isSpectator(),
			AbilitiesLayer::ABILITY_MINE => !$for->isSpectator(),
			AbilitiesLayer::ABILITY_DOORS_AND_SWITCHES => !$for->isSpectator(),
			AbilitiesLayer::ABILITY_OPEN_CONTAINERS => !$for->isSpectator(),
			AbilitiesLayer::ABILITY_ATTACK_PLAYERS => !$for->isSpectator(),
			AbilitiesLayer::ABILITY_ATTACK_MOBS => !$for->isSpectator(),
			AbilitiesLayer::ABILITY_PRIVILEGED_BUILDER => false,
		];

		$layers = [
			new AbilitiesLayer(AbilitiesLayer::LAYER_BASE, $boolAbilities, $for->getFlightSpeedMultiplier(), 1, 0.1),
		];
		if(!$for->hasBlockCollision()){
			//TODO: HACK! In 1.19.80, the client starts falling in our faux spectator mode when it clips into a
			//block. We can't seem to prevent this short of forcing the player to always fly when block collision is
			//disabled. Also, for some reason the client always reads flight state from this layer if present, even
			//though the player isn't in spectator mode.

			$layers[] = new AbilitiesLayer(AbilitiesLayer::LAYER_SPECTATOR, [
				AbilitiesLayer::ABILITY_FLYING => true,
			], null, null, null);
		}

		$this->sendDataPacket(Packets::updateAbilities(["data" => Fields::abilities(new AbilitiesData(
			$isOp ? CommandPermissions::OPERATOR : CommandPermissions::NORMAL,
			$isOp ? PlayerPermissions::OPERATOR : PlayerPermissions::MEMBER,
			$for->getId(),
			$layers
		))]));
	}

	public function syncAdventureSettings() : void{
		if($this->player === null){
			throw new \LogicException("Cannot sync adventure settings for a player that is not yet created");
		}
		//everything except auto jump is handled via UpdateAbilitiesPacket
		$this->sendDataPacket(Packets::updateAdventureSettings(["adventure_settings" => [
			"no_pv_m" => false,
			"no_mv_p" => false,
			"immutable_world" => false,
			"show_name_tags" => true,
			"auto_jump" => $this->player->hasAutoJump(),
		]]));
	}

	public function syncAvailableCommands() : void{
		//every command has one free-form overload, plus an enum of its aliases if it has any
		$enumValues = [];
		$enumValueIndexes = [];
		$enums = [];
		$commands = [];
		foreach($this->server->getCommandMap()->getCommands() as $command){
			if(isset($commands[$command->getLabel()]) || $command->getLabel() === "help" || !$command->testPermissionSilent($this->player)){
				continue;
			}

			$lname = strtolower($command->getLabel());
			$aliases = $command->getAliases();
			$aliasEnum = -1;
			if(count($aliases) > 0){
				if(!in_array($lname, $aliases, true)){
					//work around a client bug which makes the original name not show when aliases are used
					$aliases[] = $lname;
				}
				$valueIndexes = [];
				foreach($aliases as $alias){
					if(!isset($enumValueIndexes[$alias])){
						$enumValueIndexes[$alias] = count($enumValues);
						$enumValues[] = $alias;
					}
					$valueIndexes[] = $enumValueIndexes[$alias];
				}
				$aliasEnum = count($enums);
				$enums[] = ["name" => ucfirst($command->getLabel()) . "Aliases", "values" => $valueIndexes];
			}

			$description = $command->getDescription();
			$commands[$command->getLabel()] = [
				"name" => $lname, //TODO: commands containing uppercase letters in the name crash 1.9.0 client
				"description" => $description instanceof Translatable ? $this->player->getLanguage()->translate($description) : $description,
				"flags" => 0,
				"permission_level" => CommandPermissions::NORMAL->value,
				"alias_enum" => $aliasEnum,
				"command_data_chained_subcommand_indexes" => [],
				"overloads" => [[
					"is_chaining" => false,
					"parameter_data" => [[
						"name" => "args",
						"parse_symbol" => AvailableCommandsPacket::ARG_FLAG_VALID | AvailableCommandsPacket::ARG_TYPE_RAWTEXT,
						"is_optional" => true,
						"options" => 0,
					]],
				]],
			];
		}

		$this->sendDataPacket(Packets::availableCommands([
			"enum_values" => $enumValues,
			"chained_subcommand_values" => [],
			"post_fixes" => [],
			"enum_data" => $enums,
			"chained_subcommand_data" => [],
			"commands" => array_values($commands),
			"soft_enums" => [],
			"constraints" => [],
		]));
	}

	/**
	 * @return string[][]
	 * @phpstan-return array{string, list<string>}
	 */
	public function prepareClientTranslatableMessage(Translatable $message) : array{
		//we can't send nested translations to the client, so make sure they are always pre-translated by the server
		$language = $this->player->getLanguage();
		$parameters = array_map(fn(string|Translatable $p) => $p instanceof Translatable ? $language->translate($p) : $p, $message->getParameters());
		$untranslatedParameterCount = 0;
		$translated = $language->translateString($message->getText(), $parameters, "quark.", $untranslatedParameterCount);
		return [$translated, array_values(array_slice($parameters, 0, $untranslatedParameterCount))];
	}

	public function onChatMessage(Translatable|string $message) : void{
		if($message instanceof Translatable){
			if(!$this->server->isLanguageForced()){
				$this->sendDataPacket(TextPackets::translation(...$this->prepareClientTranslatableMessage($message)));
			}else{
				$this->sendDataPacket(TextPackets::raw($this->player->getLanguage()->translate($message)));
			}
		}else{
			$this->sendDataPacket(TextPackets::raw($message));
		}
	}

	public function onJukeboxPopup(Translatable|string $message) : void{
		$parameters = [];
		if($message instanceof Translatable){
			if(!$this->server->isLanguageForced()){
				[$message, $parameters] = $this->prepareClientTranslatableMessage($message);
			}else{
				$message = $this->player->getLanguage()->translate($message);
			}
		}
		$this->sendDataPacket(TextPackets::jukeboxPopup($message, $parameters));
	}

	public function onPopup(string $message) : void{
		$this->sendDataPacket(TextPackets::popup($message));
	}

	public function onTip(string $message) : void{
		$this->sendDataPacket(TextPackets::tip($message));
	}

	public function onFormSent(int $id, Form $form) : bool{
		return $this->sendDataPacket(Packets::modalFormRequest(["form_id" => $id, "form_ui_json" => json_encode($form, JSON_THROW_ON_ERROR)]));
	}

	public function onCloseAllForms() : void{
		$this->sendDataPacket(Packets::clientBoundCloseForm([]));
	}

	/**
	 * @phpstan-param \Closure() : void $onCompletion
	 */
	private function sendChunkPacket(string $chunkPacket, \Closure $onCompletion, World $world) : void{
		$syncChunkSendScope = $world->pulse->syncChunkSend->start();
		try{
			$this->queueCompressed($chunkPacket);
			$onCompletion();
		}finally{
			$world->pulse->syncChunkSend->stop($syncChunkSendScope);
		}
	}

	/**
	 * Instructs the networksession to start using the chunk at the given coordinates. This may occur asynchronously.
	 * @param \Closure $onCompletion To be called when chunk sending has completed.
	 * @phpstan-param \Closure() : void $onCompletion
	 */
	public function startUsingChunk(int $chunkX, int $chunkZ, \Closure $onCompletion) : void{
		$world = $this->player->getLocation()->getWorld();
		$promiseOrPacket = ChunkCache::getInstance($world, $this->compressor)->request($chunkX, $chunkZ);
		if(is_string($promiseOrPacket)){
			$this->sendChunkPacket($promiseOrPacket, $onCompletion, $world);
			return;
		}
		$promiseOrPacket->onResolve(
			//this callback may be called synchronously or asynchronously, depending on whether the promise is resolved yet
			function(CompressBatchPromise $promise) use ($world, $onCompletion, $chunkX, $chunkZ) : void{
				if(!$this->isConnected()){
					return;
				}
				$currentWorld = $this->player->getLocation()->getWorld();
				if($world !== $currentWorld || ($status = $this->player->getUsedChunkStatus($chunkX, $chunkZ)) === null){
					$this->logger->debug("Tried to send no-longer-active chunk $chunkX $chunkZ in world " . $world->getFolderName());
					return;
				}
				if($status !== UsedChunkStatus::REQUESTED_SENDING){
					//TODO: make this an error
					//this could be triggered due to the shitty way that chunk resends are handled
					//right now - not because of the spammy re-requesting, but because the chunk status reverts
					//to NEEDED if they want to be resent.
					return;
				}
				$this->sendChunkPacket($promise->getResult(), $onCompletion, $world);
			}
		);
	}

	public function stopUsingChunk(int $chunkX, int $chunkZ) : void{

	}

	public function onEnterWorld() : void{
		if($this->player !== null){
			$world = $this->player->getWorld();
			$this->syncWorldTime($world->getTime());
			$this->syncWorldDifficulty($world->getDifficulty());
			$this->syncWorldSpawnPoint($world->getSpawnLocation());
			//TODO: weather needs to be synced here (when implemented)
		}
	}

	public function syncWorldTime(int $worldTime) : void{
		$this->sendDataPacket(Packets::setTime(["time" => $worldTime]));
	}

	public function syncWorldDifficulty(int $worldDifficulty) : void{
		$this->sendDataPacket(Packets::setDifficulty(["difficulty" => $worldDifficulty]));
	}

	public function getInvManager() : ?InventoryManager{
		return $this->invManager;
	}

	/**
	 * @param Player[] $players
	 */
	public function syncPlayerList(array $players) : void{
		$entries = [];
		foreach($players as $player){
			$entries[] = Fields::playerListAddition($player->getUniqueId(), $player->getId(), $player->getDisplayName(), $this->typeConverter->getSkinAdapter()->toSkinData($player->getSkin()), $player->getXuid());
		}
		$this->sendDataPacket(Packets::playerList(["entries" => $entries]));
	}

	public function onPlayerAdded(Player $p) : void{
		$this->sendDataPacket(Packets::playerList(["entries" => [Fields::playerListAddition($p->getUniqueId(), $p->getId(), $p->getDisplayName(), $this->typeConverter->getSkinAdapter()->toSkinData($p->getSkin()), $p->getXuid())]]));
	}

	public function onPlayerRemoved(Player $p) : void{
		if($p !== $this->player){
			$this->sendDataPacket(Packets::playerList(["entries" => [Fields::playerListRemoval($p->getUniqueId())]]));
		}
	}

	private static function title(int $type, string $text = "", int $fadeIn = 0, int $stay = 0, int $fadeOut = 0) : Packet{
		return Packets::setTitle([
			"title_type" => $type,
			"title_text" => $text,
			"fade_in_time" => $fadeIn,
			"stay_time" => $stay,
			"fade_out_time" => $fadeOut,
			"xuid" => "",
			"platform_online_id" => "",
			"filtered_title_message" => "",
		]);
	}

	public function onTitle(string $title) : void{
		$this->sendDataPacket(self::title(SetTitlePacket::TYPE_SET_TITLE, $title));
	}

	public function onSubTitle(string $subtitle) : void{
		$this->sendDataPacket(self::title(SetTitlePacket::TYPE_SET_SUBTITLE, $subtitle));
	}

	public function onActionBar(string $actionBar) : void{
		$this->sendDataPacket(self::title(SetTitlePacket::TYPE_SET_ACTIONBAR_MESSAGE, $actionBar));
	}

	public function onClearTitle() : void{
		$this->sendDataPacket(self::title(SetTitlePacket::TYPE_CLEAR_TITLE));
	}

	public function onResetTitleOptions() : void{
		$this->sendDataPacket(self::title(SetTitlePacket::TYPE_RESET_TITLE));
	}

	public function onTitleDuration(int $fadeIn, int $stay, int $fadeOut) : void{
		$this->sendDataPacket(self::title(SetTitlePacket::TYPE_SET_ANIMATION_TIMES, "", $fadeIn, $stay, $fadeOut));
	}

	public function onToastNotification(string $title, string $body) : void{
		$this->sendDataPacket(Packets::toastRequest(["title" => $title, "content" => $body]));
	}

	public function onOpenSignEditor(Vector3 $signPosition, bool $frontSide) : void{
		$this->sendDataPacket(Packets::openSign(["pos" => Fields::blockPosition($signPosition), "is_front_side" => $frontSide]));
	}

	public function onItemCooldownChanged(Item $item, int $ticks) : void{
		$this->sendDataPacket(Packets::clientStartItemCooldown([
			"item_category" => GlobalItemDataHandlers::getSerializer()->serializeType($item)->getName(),
			"duration_ticks" => $ticks,
		]));
	}

	public function tick() : void{
		$this->latencySensitiveFlushPerformedThisTick = false;
		if(!$this->isConnected()){
			$this->dispose();
			return;
		}

		$this->getPulseNetwork();
		if($this->info === null){
			if(time() >= $this->connectTime + 10){
				$this->disconnectWithError(KnownTranslationFactory::quark_disconnect_error_loginTimeout());
			}

			return;
		}

		if($this->player !== null){
			$this->player->doChunkRequests();
			$this->synchronizePlayerAttributes();
		}
		$playerNetworkSendInventorySyncScope = PulseZones::$playerNetworkSendInventorySync->start();
		try{
			$this->invManager?->flushPendingUpdates();
		}finally{
			PulseZones::$playerNetworkSendInventorySync->stop($playerNetworkSendInventorySyncScope);
		}

		$this->flushGamePacketQueue();
	}
}
