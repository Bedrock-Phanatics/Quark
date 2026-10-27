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

use Bedwire\PacketId;
use Bedwire\Protocol;
use PHPUnit\Framework\TestCase;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\RegisteredListener;
use quark\event\server\DataPacketReceiveEvent;
use quark\event\server\DataPacketSendEvent;
use quark\network\mcpe\compression\Compressor;
use quark\network\mcpe\handler\PacketHandler;
use quark\network\mcpe\handler\PacketHandlerInspector;
use quark\network\mcpe\protocol\Packet;
use quark\network\mcpe\protocol\Packets;
use quark\plugin\Plugin;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;

/**
 * Checks that plugins can still cancel and replace packets in DataPacketReceiveEvent and DataPacketSendEvent.
 */
final class PacketEventTest extends TestCase{

	protected function setUp() : void{
		Pulse::reset();
		PulseZones::init();
	}

	protected function tearDown() : void{
		HandlerListManager::global()->getListFor(DataPacketReceiveEvent::class)->clear();
		HandlerListManager::global()->getListFor(DataPacketSendEvent::class)->clear();
		Pulse::reset();
	}

	/**
	 * @phpstan-template TEvent of DataPacketReceiveEvent|DataPacketSendEvent
	 * @phpstan-param class-string<TEvent>   $event
	 * @phpstan-param \Closure(TEvent) : void $handler
	 */
	private function listen(string $event, \Closure $handler) : void{
		HandlerListManager::global()->getListFor($event)->register(new RegisteredListener($handler, EventPriority::NORMAL, $this->createMock(Plugin::class), false, Pulse::zone("test.packet_event")));
	}

	private function set(object $object, string $property, mixed $value) : void{
		new \ReflectionProperty(NetworkSession::class, $property)->setValue($object, $value);
	}

	/**
	 * @phpstan-return list<string|array{int, array<string, mixed>}>
	 */
	private function sendBuffer(NetworkSession $session) : array{
		/** @phpstan-var list<string|array{int, array<string, mixed>}> $buffer */
		$buffer = new \ReflectionProperty(NetworkSession::class, "sendBuffer")->getValue($session);
		return $buffer;
	}

	/**
	 * @phpstan-param \Closure(array<string, mixed>) : bool $chunkRadius
	 */
	private function session(?\Closure $chunkRadius = null, bool $loggedIn = true) : NetworkSession{
		$session = new \ReflectionClass(NetworkSession::class)->newInstanceWithoutConstructor();
		$this->set($session, "logger", new \PrefixedLogger($this->createMock(\Logger::class), "test"));
		$this->set($session, "packetBatchLimiter", new PacketRateLimiter("batch", 10000, 1));
		$this->set($session, "gamePacketLimiter", new PacketRateLimiter("packet", 10000, 1));
		$this->set($session, "loggedIn", $loggedIn);
		$this->set($session, "ip", "127.0.0.1");
		$this->set($session, "port", 19132);
		$handler = new class($chunkRadius ?? static fn(array $packet) : bool => true) extends PacketHandler{
			/** @var list<array<string, mixed>> */
			public array $received = [];

			/** @param \Closure(array<string, mixed>) : bool $callback */
			public function __construct(private \Closure $callback){}

			/** @param array<string, mixed> $packet */
			public function handleRequestChunkRadius(array $packet) : bool{
				$this->received[] = $packet;
				return ($this->callback)($packet);
			}
		};
		[$actions, $methods] = PacketHandlerInspector::inspect($handler);
		$closures = [];
		foreach($methods as $id => $method){
			$closures[$id] = new \ReflectionMethod($handler, $method)->getClosure($handler);
		}
		$this->set($session, "handler", $handler);
		$this->set($session, "handlerActions", $actions);
		$this->set($session, "handlerMethods", $closures);
		return $session;
	}

	/**
	 * @phpstan-return list<array<string, mixed>>
	 */
	private static function received(NetworkSession $session) : array{
		$handler = $session->getHandler();
		self::assertNotNull($handler);
		/** @phpstan-var list<array<string, mixed>> $received */
		$received = new \ReflectionProperty($handler, "received")->getValue($handler);
		return $received;
	}

	private static function chunkRadius(int $radius) : string{
		return Protocol::encode(PacketId::REQUEST_CHUNK_RADIUS, ["chunk_radius" => $radius, "max_chunk_radius" => 32]);
	}

	public function testReceivedPacketsReachHandlersUnchangedWithoutListeners() : void{
		$session = $this->session();
		$session->handleDataPacket(PacketId::REQUEST_CHUNK_RADIUS, self::chunkRadius(8));
		self::assertSame([["chunk_radius" => 8, "max_chunk_radius" => 32]], self::received($session));
	}

	public function testReceiveListenersCanReplaceFields() : void{
		$seen = [];
		$this->listen(DataPacketReceiveEvent::class, static function(DataPacketReceiveEvent $event) use (&$seen) : void{
			$packet = $event->getPacket();
			$seen[] = $packet->getFields();
			$packet->setFields(["chunk_radius" => 4, "max_chunk_radius" => 6]);
		});
		$session = $this->session();
		$session->handleDataPacket(PacketId::REQUEST_CHUNK_RADIUS, self::chunkRadius(8));
		self::assertSame([["chunk_radius" => 8, "max_chunk_radius" => 32]], $seen);
		self::assertSame([["chunk_radius" => 4, "max_chunk_radius" => 6]], self::received($session));
	}

	public function testReceiveListenersCanCancel() : void{
		$this->listen(DataPacketReceiveEvent::class, static function(DataPacketReceiveEvent $event) : void{
			if($event->getPacket()->getFields()["chunk_radius"] === 8){
				$event->cancel();
			}
		});
		$session = $this->session();
		$session->handleDataPacket(PacketId::REQUEST_CHUNK_RADIUS, self::chunkRadius(8));
		$session->handleDataPacket(PacketId::REQUEST_CHUNK_RADIUS, self::chunkRadius(9));
		self::assertSame([["chunk_radius" => 9, "max_chunk_radius" => 32]], self::received($session));
	}

	public function testSendListenersCanReplaceAndCancelPackets() : void{
		$this->listen(DataPacketSendEvent::class, static function(DataPacketSendEvent $event) : void{
			$packets = $event->getPackets();
			$fields = $packets[0]->getFields();
			if($fields["chunk_radius"] === 1){
				$event->cancel();
			}elseif($fields["chunk_radius"] === 2){
				$event->setPackets([Packets::chunkRadiusUpdated(["chunk_radius" => 3]), Packets::setTime(["time" => 7])]);
			}
		});
		$session = $this->session();
		self::assertFalse($session->sendDataPacket(Packets::chunkRadiusUpdated(["chunk_radius" => 1])));
		self::assertSame([], $this->sendBuffer($session));
		self::assertTrue($session->sendDataPacket(Packets::chunkRadiusUpdated(["chunk_radius" => 2])));
		$batch = NetworkSession::encodeBatch($this->sendBuffer($session));
		self::assertSame(
			Protocol::encodeBatch([Protocol::encode(PacketId::CHUNK_RADIUS_UPDATED, ["chunk_radius" => 3]), Protocol::encode(PacketId::SET_TIME, ["time" => 7])]),
			$batch
		);
	}

	public function testSendListenersCannotQueueServerboundPackets() : void{
		$this->listen(DataPacketSendEvent::class, static function(DataPacketSendEvent $event) : void{
			$event->setPackets([Packet::fromFields(PacketId::RESOURCE_PACKS_READY_FOR_VALIDATION, [])]);
		});
		$this->expectException(\InvalidArgumentException::class);
		$this->session()->sendDataPacket(Packets::chunkRadiusUpdated(["chunk_radius" => 2]));
	}

	public function testSendListenersCannotQueuePacketsBeforeLogin() : void{
		$this->listen(DataPacketSendEvent::class, static function(DataPacketSendEvent $event) : void{
			$event->setPackets([Packets::setTime(["time" => 7])]);
		});
		$session = $this->session(loggedIn: false);
		try{
			$session->sendDataPacket(Packets::playStatus(["status" => 0]));
			self::fail("Packet queued before login");
		}catch(\InvalidArgumentException){}
		self::assertSame([], $this->sendBuffer($session));
	}

	/**
	 * @return \Generator<string, array{int}>
	 */
	public static function broadcastRecipientsProvider() : \Generator{
		yield "one recipient" => [1];
		yield "recipients sharing a compressed batch" => [2];
	}

	/**
	 * @dataProvider broadcastRecipientsProvider
	 */
	public function testBroadcastsCannotReachSessionsBeforeLogin(int $count) : void{
		$compressor = $this->createMock(Compressor::class);
		$compressor->method("getCompressionThreshold")->willReturn(0);
		$recipients = [];
		for($i = 0; $i < $count; ++$i){
			$recipient = $this->session(loggedIn: false);
			$this->set($recipient, "compressor", $compressor);
			$recipients[] = $recipient;
		}
		$broadcaster = new \ReflectionClass(StandardPacketBroadcaster::class)->newInstanceWithoutConstructor();
		try{
			$broadcaster->broadcastPackets($recipients, [Packets::setTime(["time" => 7])]);
			self::fail("Packet broadcast before login");
		}catch(\InvalidArgumentException){}
		foreach($recipients as $recipient){
			self::assertSame([], $this->sendBuffer($recipient));
		}
	}

	public function testServerboundPacketsCannotBeSentOrBroadcast() : void{
		$packet = Packet::fromFields(PacketId::RESOURCE_PACKS_READY_FOR_VALIDATION, []);
		try{
			$this->session()->sendDataPacket($packet);
			self::fail("Serverbound packet sent");
		}catch(\InvalidArgumentException){}
		$broadcaster = new \ReflectionClass(StandardPacketBroadcaster::class)->newInstanceWithoutConstructor();
		$this->expectException(\InvalidArgumentException::class);
		$broadcaster->broadcastPackets([$this->session()], [$packet]);
	}
}
