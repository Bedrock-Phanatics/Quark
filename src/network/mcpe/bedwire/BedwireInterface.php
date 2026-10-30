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

namespace quark\network\mcpe\bedwire;

use Bedwire\BackpressureException;
use Bedwire\Exception as BedwireException;
use Bedwire\Server as BedwireServer;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\DataDecodeException;
use pmmp\encoding\VarInt;
use quark\lang\KnownTranslationFactory;
use quark\lang\Translatable;
use quark\network\AdvancedNetworkInterface;
use quark\network\mcpe\compression\Compressor;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\EntityEventBroadcaster;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\PacketBroadcaster;
use quark\network\mcpe\protocol\Packet;
use quark\network\Network;
use quark\network\NetworkInterfaceStartException;
use quark\network\PacketHandlingException;
use quark\player\GameMode;
use quark\pulse\internal\PulseZones;
use quark\Server;
use quark\utils\TextFormat;
use quark\utils\Utils;
use function count;
use function implode;
use function intdiv;
use function mb_strcut;
use function spl_object_id;
use function strlen;
use function strrpos;
use function substr;
use function time;
use function trim;

/**
 * Serves players over NetherNet through ext-bedwire. Its network thread does signaling, WebRTC, batching,
 * compression and login verification, so sessions here only see whole, decompressed game packets.
 */
final class BedwireInterface implements AdvancedNetworkInterface{

	private const int EVENTS_PER_POLL = 4096;
	/** The command ring is shared, so a full one only means the network thread is behind, not that a client is slow. */
	private const int MAX_DEFERRED_BYTES = 64 * 1024 * 1024;
	private const int MAX_DEFERRED_TICKS = 100;

	private ?BedwireServer $bedwire = null;

	/** @var array<int, NetworkSession> */
	private array $sessions = [];

	/** @phpstan-var array<int, int> session IDs by spl_object_id() of their NetworkSession */
	private array $sessionIds = [];

	/** @phpstan-var array<int, true> */
	private array $pendingDisconnects = [];

	/** @phpstan-var array<int, list<array{int, \Closure() : void}>> sends the command ring had no room for, in order, with their sizes */
	private array $deferred = [];
	/** @phpstan-var array<int, int> tick each session's deferred sends started at */
	private array $deferredSince = [];
	private int $deferredBytes = 0;

	/** @phpstan-var array<string, int> expiry times of blocked addresses */
	private array $blockedAddresses = [];

	/** @phpstan-var (\Closure(NetworkSession, string) : void)|null */
	private ?\Closure $unreliablePacketHandler = null;

	private ?BedwireWakeupThread $wakeupThread = null;
	private ?int $sleeperNotifierId = null;

	private string $name = "";
	private string $publishedStatus = "";
	private int $bytesSent = 0;
	private int $bytesReceived = 0;

	private Network $network;

	/**
	 * @phpstan-param array<string, mixed> $options
	 */
	public function __construct(
		private Server $server,
		private string $ip,
		private int $port,
		private array $options,
		private Compressor $compressor,
		private PacketBroadcaster $packetBroadcaster,
		private EntityEventBroadcaster $entityEventBroadcaster,
		private TypeConverter $typeConverter
	){}

	public function start() : void{
		try{
			$bedwire = $this->bedwire = new BedwireServer($this->ip, $this->port, $this->options);
		}catch(BedwireException $e){
			throw new NetworkInterfaceStartException("Failed to start Bedwire on $this->ip:$this->port: " . $e->getMessage(), 0, $e);
		}catch(\ValueError|\TypeError $e){
			throw new NetworkInterfaceStartException("Invalid Bedwire settings: " . $e->getMessage(), 0, $e);
		}
		$this->publishStatus();

		$sleeperEntry = $this->server->getTickSleeper()->addNotifier(function() : void{
			$scope = PulseZones::$connection->start();
			try{
				$this->processEvents();
			}finally{
				PulseZones::$connection->stop($scope);
			}
		});
		$this->sleeperNotifierId = $sleeperEntry->getNotifierId();
		$this->wakeupThread = new BedwireWakeupThread($bedwire->eventSignal(), $sleeperEntry);
		$this->wakeupThread->start();
		$this->server->getLogger()->debug("Bedwire is listening on $this->ip:" . $bedwire->getPort());
	}

	public function setNetwork(Network $network) : void{
		$this->network = $network;
	}

	public function setName(string $name) : void{
		$this->name = $name;
		$this->publishStatus();
	}

	/**
	 * The status shown to clients asking about the server: name, world, player limit and game mode.
	 */
	private function publishStatus() : void{
		if($this->bedwire === null){
			return;
		}
		$rawName = $this->name !== "" ? $this->name : $this->server->getMotd();
		$level = $this->server->getName();
		$maxPlayers = $this->server->getMaxPlayers();
		$gameType = match($this->server->getGamemode()){
			GameMode::CREATIVE, GameMode::SPECTATOR => 1,
			GameMode::ADVENTURE => 2,
			default => 0
		};
		$status = "$rawName\0$level\0$maxPlayers\0$gameType";
		if($status !== $this->publishedStatus){
			$this->publishedStatus = $status;
			$name = TextFormat::clean($rawName);
			$this->bedwire->setStatus(mb_strcut($name, 0, 256, "UTF-8"), mb_strcut($level, 0, 256, "UTF-8"), $maxPlayers, $gameType);
		}
	}

	public function tick() : void{
		$bedwire = $this->bedwire;
		if($bedwire === null){
			return;
		}
		if(!$bedwire->isRunning()){
			throw new \RuntimeException("Bedwire stopped unexpectedly");
		}
		$this->processEvents();
		$this->publishStatus();
		$this->flushDeferred();
		foreach($this->pendingDisconnects as $sessionId => $_){
			if(isset($this->deferred[$sessionId])){
				continue;
			}
			try{
				$bedwire->disconnect($sessionId);
			}catch(BackpressureException){
				break;
			}
			unset($this->pendingDisconnects[$sessionId]);
		}

		$stats = $bedwire->getStatistics();
		$this->network->getBandwidthTracker()->add($stats["bytes_sent"] - $this->bytesSent, $stats["bytes_received"] - $this->bytesReceived);
		$this->bytesSent = $stats["bytes_sent"];
		$this->bytesReceived = $stats["bytes_received"];
	}

	private function processEvents() : void{
		$bedwire = $this->bedwire;
		if($bedwire === null){
			return;
		}
		do{
			$events = $bedwire->poll(self::EVENTS_PER_POLL);
			$count = count($events);
			//consecutive packets of one session are handled as one batch, keeping their order
			$batchSession = null;
			$batch = [];
			for($i = 0; $i < $count; $i += 3){
				/** @var int $kind */
				$kind = $events[$i];
				/** @var int $sessionId */
				$sessionId = $events[$i + 1];
				$data = $events[$i + 2];
				if($kind === BedwireServer::EVENT_PACKET && $sessionId === $batchSession){
					/** @var string $data */
					$batch[] = $data;
					continue;
				}
				if($batchSession !== null){
					$this->handlePackets($batchSession, $batch);
					$batchSession = null;
					$batch = [];
				}
				switch($kind){
					case BedwireServer::EVENT_PACKET:
						/** @var string $data */
						$batchSession = $sessionId;
						$batch[] = $data;
						break;
					case BedwireServer::EVENT_CONNECT:
						/** @phpstan-var array{string, string, string|null, bool} $data */
						$this->openSession($sessionId, ...$data);
						break;
					case BedwireServer::EVENT_LOGIN:
						/** @phpstan-var array{string, string, string, string} $data */
						($this->sessions[$sessionId] ?? null)?->setTransportVerifiedLogin(...$data);
						break;
					case BedwireServer::EVENT_UNRELIABLE:
						$session = $this->sessions[$sessionId] ?? null;
						if($session !== null && $this->unreliablePacketHandler !== null){
							/** @var string $data */
							($this->unreliablePacketHandler)($session, $data);
						}
						break;
					case BedwireServer::EVENT_DISCONNECT:
						unset($this->pendingDisconnects[$sessionId]);
						$this->discardDeferred($sessionId);
						$session = $this->sessions[$sessionId] ?? null;
						if($session !== null){
							unset($this->sessions[$sessionId], $this->sessionIds[spl_object_id($session)]);
							/** @var int $data */
							$session->onClientDisconnect(self::disconnectReason($data));
						}
						break;
				}
			}
			if($batchSession !== null){
				$this->handlePackets($batchSession, $batch);
			}
		}while($count === self::EVENTS_PER_POLL * 3);
	}

	private static function disconnectReason(int $reason) : Translatable|string{
		return match($reason){
			BedwireServer::REASON_REMOTE => KnownTranslationFactory::quark_disconnect_clientDisconnect(),
			BedwireServer::REASON_PROTOCOL => KnownTranslationFactory::quark_disconnect_error_badPacket(),
			BedwireServer::REASON_TIMEOUT => KnownTranslationFactory::quark_disconnect_error_timeout(),
			BedwireServer::REASON_AUTHENTICATION => KnownTranslationFactory::quark_disconnect_error_authentication(),
			BedwireServer::REASON_OVERLOAD => "Too much network traffic",
			BedwireServer::REASON_SHUTDOWN => "Network shutting down",
			default => "Disconnected by the server"
		};
	}

	private function openSession(int $sessionId, string $address, string $networkId, ?string $identityDigest, bool $issuerVerified) : void{
		[$ip, $port] = self::splitAddress($address);
		if(isset($this->blockedAddresses[$ip])){
			if($this->blockedAddresses[$ip] > time()){
				$this->disconnectNative($sessionId);
				return;
			}
			unset($this->blockedAddresses[$ip]);
		}

		$session = new NetworkSession(
			$this->server,
			$this->network->getSessionManager(),
			new BedwirePacketSender($sessionId, $this),
			$this->packetBroadcaster,
			$this->entityEventBroadcaster,
			$this->compressor,
			$this->typeConverter,
			$ip,
			$port,
			//always set, so sessions skip Bedrock encryption, which NetherNet's DTLS already provides
			new BedwireIdentityKey($identityDigest),
			true
		);
		$this->sessions[$sessionId] = $session;
		$this->sessionIds[spl_object_id($session)] = $sessionId;
		$session->getLogger()->debug("NetherNet network ID $networkId" . ($identityDigest !== null ? ($issuerVerified ? ", Microsoft-verified identity" : ", self-signed identity") : ""));
	}

	/**
	 * @return array{string, int}
	 */
	private static function splitAddress(string $address) : array{
		$colon = strrpos($address, ":");
		if($address === "" || $colon === false){
			return ["0.0.0.0", 0];
		}
		return [trim(substr($address, 0, $colon), "[]"), (int) substr($address, $colon + 1)];
	}

	/**
	 * @param string[] $packets
	 * @phpstan-param list<string> $packets
	 */
	private function handlePackets(int $sessionId, array $packets) : void{
		$session = $this->sessions[$sessionId] ?? null;
		if($session === null){
			return;
		}

		try{
			$session->handleDecodedPackets($packets);
		}catch(PacketHandlingException $e){
			$session->disconnectWithError(
				reason: "Bad packet: " . $e->getMessage(),
				disconnectScreenMessage: KnownTranslationFactory::quark_disconnect_error_badPacket()
			);
			$session->getLogger()->debug(implode("\n", Utils::printableExceptionInfo($e)));
		}catch(\Throwable $e){
			$this->server->getLogger()->emergency("Crash occurred while handling a packet from session: " . $session->getDisplayName());
			throw $e;
		}
	}

	/**
	 * @internal
	 */
	public function putBatch(int $sessionId, string $batch, int $offset) : void{
		if($this->bedwire === null || !isset($this->sessions[$sessionId])){
			return;
		}
		try{
			$this->sendRawBatch($this->bedwire, $sessionId, $batch, $offset);
		}catch(\ValueError $e){
			$this->sessions[$sessionId]->disconnectWithError("Failed to send packet: " . $e->getMessage());
		}
	}

	/**
	 * @internal
	 * @phpstan-param list<Packet|string> $packets
	 * @return bool false if the packets need encoding and sending another way
	 */
	public function putPackets(int $sessionId, array $packets) : bool{
		if($this->bedwire === null || !isset($this->sessions[$sessionId])){
			return true;
		}
		if(isset($this->deferred[$sessionId])){
			return false;
		}
		try{
			$this->bedwire->sendPackets($sessionId, $packets);
		}catch(BackpressureException|\ValueError|\TypeError){
			return false;
		}
		return true;
	}

	/**
	 * Queues the batch behind any deferred sends of the session, or defers it when the command ring is full.
	 *
	 * @throws \ValueError
	 */
	private function sendRawBatch(BedwireServer $bedwire, int $sessionId, string $batch, int $offset) : void{
		if(isset($this->deferred[$sessionId])){
			$this->defer($sessionId, strlen($batch) - $offset, fn() => $this->sendRawBatch($bedwire, $sessionId, $batch, $offset));
			return;
		}
		try{
			$bedwire->sendRawBatch($sessionId, $batch, $offset);
		}catch(BackpressureException){
			$this->defer($sessionId, strlen($batch) - $offset, fn() => $this->sendRawBatch($bedwire, $sessionId, $batch, $offset));
		}catch(\ValueError $e){
			$ends = [];
			$reader = new ByteBufferReader($batch);
			$reader->setOffset($offset);
			try{
				while($reader->getUnreadLength() > 0){
					$reader->readByteArray(VarInt::readUnsignedInt($reader));
					$ends[] = $reader->getOffset();
				}
			}catch(DataDecodeException){
				throw $e;
			}
			if(count($ends) < 2){
				throw $e;
			}
			$middle = $ends[intdiv(count($ends), 2) - 1];
			$this->sendRawBatch($bedwire, $sessionId, substr($batch, $offset, $middle - $offset), 0);
			$this->sendRawBatch($bedwire, $sessionId, $batch, $middle);
		}
	}

	/**
	 * @internal
	 */
	public function acceptLogin(int $sessionId, string $clientPublicKeyDer) : void{
		if($this->bedwire === null || !isset($this->sessions[$sessionId])){
			return;
		}
		$this->acceptLoginNow($this->bedwire, $sessionId, $clientPublicKeyDer);
	}

	private function acceptLoginNow(BedwireServer $bedwire, int $sessionId, string $clientPublicKeyDer) : void{
		if(isset($this->deferred[$sessionId])){
			$this->defer($sessionId, strlen($clientPublicKeyDer), fn() => $this->acceptLoginNow($bedwire, $sessionId, $clientPublicKeyDer));
			return;
		}
		try{
			$bedwire->acceptLogin($sessionId, $clientPublicKeyDer);
		}catch(BackpressureException){
			$this->defer($sessionId, strlen($clientPublicKeyDer), fn() => $this->acceptLoginNow($bedwire, $sessionId, $clientPublicKeyDer));
		}catch(\ValueError $e){
			$this->dropSession($sessionId, "Invalid login key: " . $e->getMessage());
		}
	}

	/**
	 * @phpstan-param \Closure() : void $send
	 */
	private function defer(int $sessionId, int $bytes, \Closure $send) : void{
		if($this->deferredBytes + $bytes > self::MAX_DEFERRED_BYTES){
			$this->dropSession($sessionId, "Network send queue is full");
			return;
		}
		$this->deferred[$sessionId][] = [$bytes, $send];
		$this->deferredSince[$sessionId] ??= $this->server->getTick();
		$this->deferredBytes += $bytes;
	}

	/**
	 * Retries deferred sends in order, until the command ring fills up again.
	 */
	private function flushDeferred() : void{
		$tick = $this->server->getTick();
		foreach($this->deferred as $sessionId => $queue){
			$since = $this->deferredSince[$sessionId];
			if($tick - $since > self::MAX_DEFERRED_TICKS){
				$this->dropSession($sessionId, "Network send queue is stuck");
				continue;
			}
			unset($this->deferred[$sessionId], $this->deferredSince[$sessionId]);
			foreach($queue as [$bytes, $send]){
				$this->deferredBytes -= $bytes;
				try{
					$send();
				}catch(\ValueError $e){
					$this->dropSession($sessionId, "Failed to send packet: " . $e->getMessage());
					break;
				}
			}
			if(isset($this->deferred[$sessionId])){
				$this->deferredSince[$sessionId] = $since;
				break;
			}
		}
	}

	private function discardDeferred(int $sessionId) : void{
		foreach($this->deferred[$sessionId] ?? [] as [$bytes]){
			$this->deferredBytes -= $bytes;
		}
		unset($this->deferred[$sessionId], $this->deferredSince[$sessionId]);
	}

	/**
	 * Closes the session at once, dropping whatever it still had to send.
	 */
	private function dropSession(int $sessionId, string $reason) : void{
		$this->discardDeferred($sessionId);
		$session = $this->sessions[$sessionId] ?? null;
		if($session !== null){
			$this->close($sessionId);
			$session->disconnectWithError($reason);
		}else{
			$this->disconnectNative($sessionId);
		}
	}

	/**
	 * @internal
	 */
	public function close(int $sessionId) : void{
		if(isset($this->sessions[$sessionId])){
			unset($this->sessionIds[spl_object_id($this->sessions[$sessionId])], $this->sessions[$sessionId]);
			if(isset($this->deferred[$sessionId])){
				//the disconnect waits for what the session still had to send, such as its kick message
				$this->pendingDisconnects[$sessionId] = true;
			}else{
				$this->disconnectNative($sessionId);
			}
		}
	}

	private function disconnectNative(int $sessionId) : void{
		try{
			$this->bedwire?->disconnect($sessionId);
		}catch(BackpressureException){
			$this->pendingDisconnects[$sessionId] = true;
		}
	}

	/**
	 * Sends a payload to the session's client on the unreliable channel, as it is given: it is not batched,
	 * compressed or encrypted, and it may arrive out of order or not at all. Does nothing if the session is not
	 * connected through this interface.
	 */
	public function sendUnreliablePacket(NetworkSession $session, string $payload) : void{
		$sessionId = $this->sessionIds[spl_object_id($session)] ?? null;
		if($sessionId !== null){
			$this->bedwire?->sendUnreliable($sessionId, $payload);
		}
	}

	/**
	 * Sets what receives the payloads clients send on the unreliable channel. They arrive in no particular order,
	 * so they are kept out of the session's packet stream; without a handler they are dropped.
	 *
	 * @phpstan-param (\Closure(NetworkSession $session, string $payload) : void)|null $handler
	 */
	public function setUnreliablePacketHandler(?\Closure $handler) : void{
		$this->unreliablePacketHandler = $handler;
	}

	public function shutdown() : void{
		if($this->sleeperNotifierId !== null){
			$this->server->getTickSleeper()->removeNotifier($this->sleeperNotifierId);
			$this->sleeperNotifierId = null;
		}
		if($this->bedwire !== null){
			$this->bedwire->stop(2000);
			//freeing the server closes its event signal, which ends the wakeup thread
			$this->bedwire = null;
		}
		$this->wakeupThread?->quit();
		$this->wakeupThread = null;
	}

	/**
	 * NetherNet has no raw socket to filter, so blocked addresses are disconnected as soon as they connect.
	 */
	public function blockAddress(string $address, int $timeout = 300) : void{
		$this->blockedAddresses[$address] = time() + $timeout;
	}

	public function unblockAddress(string $address) : void{
		unset($this->blockedAddresses[$address]);
	}

	public function sendRawPacket(string $address, int $port, string $payload) : void{}

	public function addRawPacketFilter(string $regex) : void{}
}
