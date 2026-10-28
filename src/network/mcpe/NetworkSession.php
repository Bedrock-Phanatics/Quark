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
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
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
use quark\network\mcpe\protocol\EncodedPacket;
use quark\network\mcpe\protocol\Fields;
use quark\network\mcpe\protocol\Packet;
use quark\network\mcpe\protocol\packet\AvailableCommandsPacket;
use quark\network\mcpe\protocol\packet\ChunkRadiusUpdatedPacket;
use quark\network\mcpe\protocol\packet\ClientBoundCloseFormPacket;
use quark\network\mcpe\protocol\packet\ClientStartItemCooldownPacket;
use quark\network\mcpe\protocol\packet\DisconnectPacket;
use quark\network\mcpe\protocol\packet\ModalFormRequestPacket;
use quark\network\mcpe\protocol\packet\MovePlayerPacket;
use quark\network\mcpe\protocol\packet\NetworkChunkPublisherUpdatePacket;
use quark\network\mcpe\protocol\packet\OpenSignPacket;
use quark\network\mcpe\protocol\packet\PlayerListPacket;
use quark\network\mcpe\protocol\packet\PlayStatusPacket;
use quark\network\mcpe\protocol\packet\ServerToClientHandshakePacket;
use quark\network\mcpe\protocol\packet\SetDifficultyPacket;
use quark\network\mcpe\protocol\packet\SetPlayerGameTypePacket;
use quark\network\mcpe\protocol\packet\SetSpawnPositionPacket;
use quark\network\mcpe\protocol\packet\SetTimePacket;
use quark\network\mcpe\protocol\packet\SetTitlePacket;
use quark\network\mcpe\protocol\packet\ToastRequestPacket;
use quark\network\mcpe\protocol\packet\TransferPacket;
use quark\network\mcpe\protocol\packet\UpdateAbilitiesPacket;
use quark\network\mcpe\protocol\packet\UpdateAdventureSettingsPacket;
use quark\network\mcpe\protocol\Packets;
use quark\network\mcpe\protocol\ProtocolClasses;
use quark\network\mcpe\protocol\TextPackets;
use quark\network\mcpe\protocol\types\AbilitiesData;
use quark\network\mcpe\protocol\types\AbilitiesLayer;
use quark\network\mcpe\protocol\types\Command;
use quark\network\mcpe\protocol\types\command\CommandPermissions;
use quark\network\mcpe\protocol\types\CommandEnum;
use quark\network\mcpe\protocol\types\CommandOverload;
use quark\network\mcpe\protocol\types\CommandParameter;
use quark\network\mcpe\protocol\types\CompressionAlgorithm;
use quark\network\mcpe\protocol\types\DimensionIds;
use quark\network\mcpe\protocol\types\DisconnectMessagesData;
use quark\network\mcpe\protocol\types\MovePlayerTeleportData;
use quark\network\mcpe\protocol\types\PlayerPermissions;
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
	private const int INCOMING_PACKET_BATCH_PER_TICK = 2; //usually max 1 per tick, but transactions arrive separately
	private const int INCOMING_PACKET_BATCH_BUFFER_TICKS = 100; //enough to account for a 5-second lag spike

	private const int INCOMING_GAME_PACKETS_PER_TICK = 2;
	private const int INCOMING_GAME_PACKETS_BUFFER_TICKS = 100;

	private const int INCOMING_PACKET_BATCH_HARD_LIMIT = 300;

	/**
	 * Array entries one received packet may decode into. Vanilla packets stay far below this, and it keeps a packet
	 * full of tiny list elements from expanding into hundreds of megabytes of arrays.
	 */
	private const int MAX_DECODED_VALUES = 65536;

	private const array PRE_LOGIN_PACKETS = [
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
	/** @phpstan-var array<int, \Closure(Packet) : bool> */
	private array $handlerMethods = [];

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
	 * @phpstan-var list<Packet|string>
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
	/** @phpstan-var array{string, string, string, string}|null display name, UUID, XUID and client key DER the transport verified */
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
	public function setTransportVerifiedLogin(string $displayName, string $uuid, string $xuid, string $clientKeyDer) : void{
		$this->transportVerifiedLogin = [$displayName, $uuid, $xuid, $clientKeyDer];
	}

	/**
	 * Returns the display name, UUID, XUID and client key DER of a login the transport already verified against
	 * Microsoft's keys, or null if the login still has to be verified here.
	 *
	 * @phpstan-return array{string, string, string, string}|null
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
			if($this->handler !== null){
				[$this->handlerActions, $methods] = PacketHandlerInspector::inspect($this->handler);
				foreach($methods as $id => $method){
					$this->handlerMethods[$id] = new \ReflectionMethod($this->handler, $method)->getClosure($this->handler);
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
			$this->handleBatchBuffers(self::splitBatch($decompressed), $network, $window, self::INCOMING_PACKET_BATCH_HARD_LIMIT);
		}finally{
			$work?->leave($workToken);
			PulseZones::$playerNetworkReceive->stop($playerNetworkReceiveScope);
		}
	}

	/**
	 * Yields the length-prefixed packets of a batch body one at a time, so a batch over the packet limit is rejected
	 * before the rest of it is read.
	 *
	 * @phpstan-return \Generator<int, string, void, void>
	 * @throws DataDecodeException
	 */
	private static function splitBatch(string $batch) : \Generator{
		$reader = new ByteBufferReader($batch);
		while($reader->getUnreadLength() > 0){
			yield $reader->readByteArray(VarInt::readUnsignedInt($reader));
		}
	}

	/**
	 * Handles the packets of one batch in order.
	 *
	 * @param iterable<string> $buffers
	 * @throws PacketHandlingException
	 */
	private function handleBatchBuffers(iterable $buffers, ?PulseNetwork $network, ?int $window, ?int $packetLimit) : void{
		$count = 0;
		$recording = Pulse::isRecording();
		ProtocolClasses::bind();
		try{
			foreach($buffers as $buffer){
				if($packetLimit !== null && ++$count >= $packetLimit){
					if($network !== null && $window !== null){ $network->count($window, PulseNetwork::PACKETS); }
					$this->recordRawPacketSecurityEvent($buffer, "batch.packet_limit", $count, $packetLimit);
					throw new PacketHandlingException("Reached hard limit of $packetLimit per batch packet");
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
				if(!isset(Packets::HANDLERS[$packetId]) && Protocol::name($packetId) === null){
					if($network !== null && $window !== null){
						$network->count($window, PulseNetwork::PACKETS);
						$network->count($window, PulseNetwork::DECODE_FAILED);
					}
					$this->recordRawPacketSecurityEvent($buffer, "packet.unknown");
					$this->logger->debug("Unknown packet: " . Utils::printablePacketBuffer($buffer));
					throw new PacketHandlingException("Unknown packet received");
				}
				try{
					$this->handlePacket($packetId, $buffer, $network, $window, $recording);
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
		}catch(PacketException|DataDecodeException $e){
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
			$this->handleBatchBuffers($buffers, $network, $window, null);
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
		ProtocolClasses::bind();
		$network = $this->getPulseNetwork();
		$this->handlePacket($packetId, $buffer, $network, $network?->window($this->pulseSessionId), Pulse::isRecording());
	}

	/**
	 * @throws PacketHandlingException
	 * @throws FilterNoisyPacketException
	 */
	private function handlePacket(int $packetId, string $buffer, ?PulseNetwork $network, ?int $window, bool $recording) : void{
		if($network !== null && $window !== null){ $network->count($window, PulseNetwork::PACKETS); }
		if(!isset(Packets::HANDLERS[$packetId])){
			$this->recordNetworkSecurityEvent("packet.direction", "reject_batch", $packetId);
			throw new PacketHandlingException("Unexpected non-serverbound packet");
		}

		$pulse = $recording ? PulseZones::getReceiveDataPacketZone($packetId) : null;
		$scope = $pulse?->start() ?? 0;
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

			$decodeZone = $recording ? PulseZones::getDecodeDataPacketZone($packetId) : null;
			$decodeZoneScope = $decodeZone?->start() ?? 0;
			try{
				$work?->stage($workToken, PulseNetworkWork::DECODE);
				try{
					$packet = Protocol::decode($buffer, self::MAX_DECODED_VALUES);
					if(!$packet instanceof Packet){
						throw new PacketException("Unknown packet ID");
					}
					if($network !== null && $window !== null){ $network->count($window, PulseNetwork::DECODED); }
				}catch(PacketException $e){
					if($network !== null && $window !== null){ $network->count($window, PulseNetwork::DECODE_FAILED); }
					$this->recordNetworkSecurityEvent("packet.malformed", "reject_batch", $packetId, $e->observed, $e->limit);
					throw PacketHandlingException::wrap($e);
				}
			}finally{
				$decodeZone?->stop($decodeZoneScope);
			}

			if(DataPacketReceiveEvent::hasHandlers()){
				$work?->stage($workToken, PulseNetworkWork::RECEIVE_EVENT);
				$ev = new DataPacketReceiveEvent($this, $packet);
				$ev->call();
				if($ev->isCancelled()){
					if($network !== null && $window !== null){ $network->count($window, PulseNetwork::PLUGIN_CANCELLED); }
					return;
				}
			}
			$handlerZone = $recording ? PulseZones::getHandleDataPacketZone($packetId) : null;
			$handlerZoneScope = $handlerZone?->start() ?? 0;
			try{
				$work?->stage($workToken, PulseNetworkWork::HANDLE);
				$method = $this->handlerMethods[$packetId] ?? null;
				if($method === null || !$method($packet)){
					if($network !== null && $window !== null){ $network->count($window, PulseNetwork::STATE_DROPPED); }
					$this->recordNetworkSecurityEvent("packet.state", "drop_packet", $packetId);
					$this->unhandledPacketDebug($packetId, $buffer, "Handler rejected");
				}
			}catch(PacketHandlingException $e){
				$this->recordNetworkSecurityEvent("packet.handler_validation", "reject_batch", $packetId, $e->observed, $e->limit);
				throw $e;
			}finally{
				$handlerZone?->stop($handlerZoneScope);
			}
		}finally{
			$work?->leave($workToken);
			$pulse?->stop($scope);
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
	private function sendDataPacketInternal(Packet|EncodedPacket $packet, bool $immediate, ?PromiseResolver $ackReceiptResolver) : bool{
		if(!$this->connected){
			return false;
		}
		$packets = [$packet];
		$this->checkSendable($packets);

		$pulse = PulseZones::getSendDataPacketZone($packet->getId());
		$scope = $pulse->start();
		try{
			if(DataPacketSendEvent::hasHandlers()){
				$ev = new DataPacketSendEvent([$this], $packets);
				$ev->call();
				if($ev->isCancelled()){
					return false;
				}
				$packets = $ev->getPackets();
			}

			$this->addToSendBuffer($packets);
			if($ackReceiptResolver !== null){
				$this->sendBufferAckPromises[] = $ackReceiptResolver;
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
	public function sendDataPacket(Packet|EncodedPacket $packet, bool $immediate = false) : bool{
		return $this->sendDataPacketInternal($packet, $immediate, null);
	}

	/**
	 * @phpstan-return Promise<true>
	 */
	public function sendDataPacketWithReceipt(Packet|EncodedPacket $packet, bool $immediate = false) : Promise{
		/** @phpstan-var PromiseResolver<true> $resolver */
		$resolver = new PromiseResolver();

		if(!$this->sendDataPacketInternal($packet, $immediate, $resolver)){
			$resolver->reject();
		}

		return $resolver->getPromise();
	}

	/**
	 * @internal
	 * @param (Packet|EncodedPacket)[] $packets
	 * @throws \InvalidArgumentException
	 */
	public function checkSendable(array $packets) : void{
		foreach($packets as $packet){
			$packetId = $packet->getId();
			if(!isset(Packets::CLIENTBOUND[$packetId])){
				throw new \InvalidArgumentException("Clients do not accept " . $packet->getName() . " packets");
			}
			if(!$this->loggedIn && !isset(self::PRE_LOGIN_PACKETS[$packetId])){
				throw new \InvalidArgumentException("Attempted to send " . $packet->getName() . " to " . $this->getDisplayName() . " too early");
			}
		}
	}

	/**
	 * @internal Queues packets the broadcaster already fired events for.
	 * @param (Packet|EncodedPacket)[] $packets
	 * @throws \InvalidArgumentException
	 */
	public function addToSendBuffer(array $packets) : void{
		$this->checkSendable($packets);
		foreach($packets as $packet){
			$this->sendBuffer[] = self::batchEntry($packet);
		}
	}

	private static function batchEntry(Packet|EncodedPacket $packet) : Packet|string{
		return $packet instanceof EncodedPacket ? $packet->getBytes() : $packet;
	}

	/**
	 * Encodes queued packets into one batch body with a single native call.
	 *
	 * @phpstan-param list<Packet|string> $packets
	 * @throws \ValueError naming the first packet whose fields do not fit it
	 * @throws \TypeError
	 */
	public static function encodeBatch(array $packets) : string{
		ProtocolClasses::bind();
		$scope = PulseZones::$playerNetworkSendEncode->start();
		try{
			return Protocol::encodeBatch($packets);
		}catch(\ValueError|\TypeError $e){
			//find the culprit, so a bad packet is reported where it was built instead of as an anonymous batch
			foreach($packets as $packet){
				if(is_string($packet)){
					continue;
				}
				try{
					Protocol::encode($packet->getId(), $packet);
				}catch(\ValueError|\TypeError $inner){
					throw new \ValueError("Invalid " . $packet->getName() . " packet: " . $inner->getMessage(), 0, $inner);
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
				$ackPromises = $this->sendBufferAckPromises;
				$this->sendBufferAckPromises = [];
				if($this->sender instanceof NativeBatchPacketSender && $this->compressedQueue->isEmpty()){
					ProtocolClasses::bind();
					$encodeScope = PulseZones::$playerNetworkSendEncode->start();
					try{
						$sent = $this->sender->sendPackets($packets);
					}finally{
						PulseZones::$playerNetworkSendEncode->stop($encodeScope);
					}
					if($sent){
						//NetherNet's reliable channel has no per-batch acknowledgements, so queueing is as far as we can follow
						foreach($ackPromises as $promise){
							$promise->resolve(true);
						}
						return;
					}
				}
				try{
					$encoded = self::encodeBatch($packets);
				}catch(\ValueError|\TypeError $e){
					foreach($ackPromises as $resolver){
						$resolver->reject();
					}
					$this->logger->logException($e);
					$this->disconnectWithError(
						reason: "Failed to encode packets: " . $e->getMessage(),
						disconnectScreenMessage: KnownTranslationFactory::quark_disconnect_error_internal()
					);
					return;
				}

				if($this->enableCompression){
					$batch = $this->server->prepareBatch($encoded, $this->compressor, $syncMode, PulseZones::$playerNetworkSendCompressSessionBuffer);
				}else{
					$batch = $encoded;
				}
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
			//held through teardown so a failing final flush cannot disconnect again
			$this->disconnectGuard = true;
			try{
				$func();
				$this->flushGamePacketQueue();
			}finally{
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
		$this->sendDataPacket(new DisconnectPacket(reason: 0, messages: ["messages" => new DisconnectMessagesData(message: $translated, filteredMessage: "")]));
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
				$this->sendDataPacket(new PlayStatusPacket(status: $protocolVersion < Protocol::VERSION ? PlayStatusPacket::LOGIN_FAILED_CLIENT : PlayStatusPacket::LOGIN_FAILED_SERVER), true);
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
			$this->sendDataPacket(new TransferPacket(serverAddress: $ip, serverPort: $port, reloadWorld: false), true);
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
				$this->sendDataPacket(new ServerToClientHandshakePacket(handshakeWebToken: $handshakeJwt), true); //make sure this gets sent before encryption is enabled

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

		$this->sendDataPacket(new PlayStatusPacket(status: PlayStatusPacket::LOGIN_SUCCESS));

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
		$this->sendDataPacket(new PlayStatusPacket(status: PlayStatusPacket::PLAYER_SPAWN));
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

			$this->sendDataPacket(new MovePlayerPacket(
				playerRuntimeId: $this->player->getId(),
				position: $this->player->getOffsetPosition($pos),
				rotation: new Vector2($pitch, $yaw),
				yHeadRotation: $yaw, //TODO: head yaw
				positionMode: $mode,
				onGround: $this->player->onGround,
				ridingRuntimeId: 0, //TODO: riding entity ID
				teleportData: $mode === MovePlayerPacket::MODE_TELEPORT ? new MovePlayerTeleportData(teleportationCause: 0, sourceActorType: 0) : null,
				tick: 0, //TODO: tick
			));

			if($this->handler instanceof InGamePacketHandler){
				$this->handler->forceMoveSync = true;
			}
		}
	}

	public function syncViewAreaRadius(int $distance) : void{
		$this->sendDataPacket(new ChunkRadiusUpdatedPacket(chunkRadius: $distance));
	}

	public function syncViewAreaCenterPoint(Vector3 $newPos, int $viewDistance) : void{
		$this->sendDataPacket(new NetworkChunkPublisherUpdatePacket(
			newPositionForView: $newPos->floor(),
			newRadiusForView: $viewDistance * 16, //blocks, not chunks >.>
			serverBuiltChunksList: [],
		));
	}

	public function syncPlayerSpawnPoint(Position $newSpawn) : void{
		$newSpawnBlockPosition = $newSpawn->floor();
		//TODO: respawn causing block position (bed, respawn anchor)
		$this->sendDataPacket(new SetSpawnPositionPacket(
			spawnPositionType: SetSpawnPositionPacket::TYPE_PLAYER_SPAWN,
			blockPosition: $newSpawnBlockPosition,
			dimensionType: DimensionIds::OVERWORLD,
			spawnBlockPos: $newSpawnBlockPosition,
		));
	}

	public function syncWorldSpawnPoint(Position $newSpawn) : void{
		$this->sendDataPacket(new SetSpawnPositionPacket(
			spawnPositionType: SetSpawnPositionPacket::TYPE_WORLD_SPAWN,
			blockPosition: $newSpawn->floor(),
			dimensionType: DimensionIds::OVERWORLD,
			spawnBlockPos: new Vector3(Limits::INT32_MIN, Limits::INT32_MIN, Limits::INT32_MIN),
		));
	}

	public function syncGameMode(GameMode $mode, bool $isRollback = false) : void{
		$this->sendDataPacket(new SetPlayerGameTypePacket(playerGameType: $this->typeConverter->coreGameModeToProtocol($mode)->value));
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

		$this->sendDataPacket(new UpdateAbilitiesPacket(data: Fields::abilities(new AbilitiesData(
			$isOp ? CommandPermissions::OPERATOR : CommandPermissions::NORMAL,
			$isOp ? PlayerPermissions::OPERATOR : PlayerPermissions::MEMBER,
			$for->getId(),
			$layers
		))));
	}

	public function syncAdventureSettings() : void{
		if($this->player === null){
			throw new \LogicException("Cannot sync adventure settings for a player that is not yet created");
		}
		//everything except auto jump is handled via UpdateAbilitiesPacket
		$this->sendDataPacket(new UpdateAdventureSettingsPacket(
			noPvM: false,
			noMvP: false,
			immutableWorld: false,
			showNameTags: true,
			autoJump: $this->player->hasAutoJump(),
		));
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
				$enums[] = new CommandEnum(name: ucfirst($command->getLabel()) . "Aliases", values: $valueIndexes);
			}

			$description = $command->getDescription();
			$commands[$command->getLabel()] = new Command(
				name: $lname, //TODO: commands containing uppercase letters in the name crash 1.9.0 client
				description: $description instanceof Translatable ? $this->player->getLanguage()->translate($description) : $description,
				flags: 0,
				permissionLevel: CommandPermissions::NORMAL->value,
				aliasEnum: $aliasEnum,
				commandDataChainedSubcommandIndexes: [],
				overloads: [new CommandOverload(
					isChaining: false,
					parameterData: [new CommandParameter(
						name: "args",
						parseSymbol: AvailableCommandsPacket::ARG_FLAG_VALID | AvailableCommandsPacket::ARG_TYPE_RAWTEXT,
						isOptional: true,
						options: 0,
					)],
				)],
			);
		}

		$this->sendDataPacket(new AvailableCommandsPacket(
			enumValues: $enumValues,
			chainedSubcommandValues: [],
			postFixes: [],
			enumData: $enums,
			chainedSubcommandData: [],
			commands: array_values($commands),
			softEnums: [],
			constraints: [],
		));
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
		return $this->sendDataPacket(new ModalFormRequestPacket(formId: $id, formUiJson: json_encode($form, JSON_THROW_ON_ERROR)));
	}

	public function onCloseAllForms() : void{
		$this->sendDataPacket(new ClientBoundCloseFormPacket());
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
		$this->sendDataPacket(new SetTimePacket(time: $worldTime));
	}

	public function syncWorldDifficulty(int $worldDifficulty) : void{
		$this->sendDataPacket(new SetDifficultyPacket(difficulty: $worldDifficulty));
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
		$this->sendDataPacket(new PlayerListPacket(entries: $entries));
	}

	public function onPlayerAdded(Player $p) : void{
		$this->sendDataPacket(new PlayerListPacket(entries: [Fields::playerListAddition($p->getUniqueId(), $p->getId(), $p->getDisplayName(), $this->typeConverter->getSkinAdapter()->toSkinData($p->getSkin()), $p->getXuid())]));
	}

	public function onPlayerRemoved(Player $p) : void{
		if($p !== $this->player){
			$this->sendDataPacket(new PlayerListPacket(entries: [Fields::playerListRemoval($p->getUniqueId())]));
		}
	}

	private static function title(int $type, string $text = "", int $fadeIn = 0, int $stay = 0, int $fadeOut = 0) : Packet{
		return new SetTitlePacket(
			titleType: $type,
			titleText: $text,
			fadeInTime: $fadeIn,
			stayTime: $stay,
			fadeOutTime: $fadeOut,
			xuid: "",
			platformOnlineId: "",
			filteredTitleMessage: "",
		);
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
		$this->sendDataPacket(new ToastRequestPacket(title: $title, content: $body));
	}

	public function onOpenSignEditor(Vector3 $signPosition, bool $frontSide) : void{
		$this->sendDataPacket(new OpenSignPacket(pos: $signPosition->floor(), isFrontSide: $frontSide));
	}

	public function onItemCooldownChanged(Item $item, int $ticks) : void{
		$this->sendDataPacket(new ClientStartItemCooldownPacket(
			itemCategory: GlobalItemDataHandlers::getSerializer()->serializeType($item)->getName(),
			durationTicks: $ticks,
		));
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
