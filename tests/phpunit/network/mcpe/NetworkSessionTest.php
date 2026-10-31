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
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\VarInt;
use pocketmine\math\Vector3;
use quark\lang\Language;
use quark\network\mcpe\protocol\packet\ContainerClosePacket;
use quark\network\mcpe\protocol\packet\ContainerOpenPacket;
use quark\network\mcpe\protocol\packet\SetTimePacket;
use quark\network\mcpe\protocol\types\inventory\WindowTypes;
use quark\network\PacketHandlingException;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;
use quark\Server;
use quark\utils\ObjectSet;
use function strlen;
use const PHP_INT_MAX;

final class NetworkSessionTest extends TestCase{

	protected function setUp() : void{
		Pulse::reset();
		PulseZones::init();
	}

	protected function tearDown() : void{
		Pulse::reset();
	}

	private function set(NetworkSession $session, string $property, mixed $value) : void{
		new \ReflectionProperty(NetworkSession::class, $property)->setValue($session, $value);
	}

	/**
	 * @phpstan-param \Closure(NetworkSession, string) : void $onSend
	 */
	private function session(\Closure $onSend) : NetworkSession{
		$session = new \ReflectionClass(NetworkSession::class)->newInstanceWithoutConstructor();
		$language = $this->createMock(Language::class);
		$language->method("translate")->willReturn("");
		$server = $this->createMock(Server::class);
		$server->method("getLanguage")->willReturn($language);
		$this->set($session, "server", $server);
		$this->set($session, "logger", new \PrefixedLogger($this->createMock(\Logger::class), "test"));
		$this->set($session, "compressedQueue", new \SplQueue());
		$this->set($session, "disposeHooks", new ObjectSet());
		$this->set($session, "loggedIn", true);
		$this->set($session, "packetBatchLimiter", new PacketRateLimiter("Packet Batches", 2, 100));
		$this->set($session, "gamePacketLimiter", new PacketRateLimiter("Game Packets", 10000, 1));
		$this->set($session, "sender", new class($session, $onSend) implements NativeBatchPacketSender{
			/** @phpstan-param \Closure(NetworkSession, string) : void $onSend */
			public function __construct(private NetworkSession $session, private \Closure $onSend){}

			public function send(string $payload, bool $immediate, ?int $receiptId) : void{
				$this->sendBatch($payload, 0);
			}

			public function sendBatch(string $batch, int $offset) : void{
				($this->onSend)($this->session, $batch);
			}

			public function sendPackets(array $packets) : bool{
				try{
					$batch = NetworkSession::encodeBatch($packets);
				}catch(\ValueError|\TypeError){
					return false;
				}
				($this->onSend)($this->session, $batch);
				return true;
			}

			public function acceptLogin(string $clientPublicKeyDer) : void{}

			public function close(string $reason = "unknown reason") : void{}
		});
		return $session;
	}

	/**
	 * @return \Generator<string, array{\Closure(NetworkSession) : mixed}>
	 */
	public static function flushProvider() : \Generator{
		yield "flush while connected" => [static fn(NetworkSession $session) => $session->sendDataPacket(new SetTimePacket(1), true)];
		yield "final flush of a disconnect" => [static fn(NetworkSession $session) => $session->disconnect("test")];
	}

	/**
	 * @dataProvider flushProvider
	 * @phpstan-param \Closure(NetworkSession) : mixed $flush
	 */
	public function testSendFailureDuringFlushDisconnectsOnce(\Closure $flush) : void{
		$sends = 0;
		$session = $this->session(static function(NetworkSession $session) use (&$sends) : void{
			$sends++;
			$session->disconnectWithError("Network send queue is full");
		});
		$disposed = 0;
		/** @phpstan-var ObjectSet<\Closure() : void> $hooks */
		$hooks = new \ReflectionProperty(NetworkSession::class, "disposeHooks")->getValue($session);
		$hooks->add(static function() use (&$disposed) : void{
			$disposed++;
		});

		$flush($session);

		self::assertFalse($session->isConnected());
		self::assertLessThanOrEqual(2, $sends);
		self::assertSame(1, $disposed);
	}

	private static function chunkRadius(int $radius) : string{
		return Protocol::encode(PacketId::REQUEST_CHUNK_RADIUS, ["chunk_radius" => $radius, "max_chunk_radius" => 32]);
	}

	public function testNativelySplitPacketsSkipBatchLimits() : void{
		$session = $this->session(static function() : void{});
		$packets = [];
		for($i = 0; $i < 400; ++$i){
			$packets[] = self::chunkRadius($i);
		}
		$session->handleDecodedPackets($packets);
		for($i = 0; $i < 250; ++$i){
			$session->handleDecodedPackets([self::chunkRadius($i)]);
		}
		self::assertTrue($session->isConnected());
	}

	public function testBatchesStillHaveAPacketLimit() : void{
		$batch = "";
		for($i = 0; $i < 400; ++$i){
			$packet = self::chunkRadius($i);
			$batch .= VarInt::packUnsignedInt(strlen($packet)) . $packet;
		}
		$this->expectException(PacketHandlingException::class);
		$this->session(static function() : void{})->handleEncoded($batch);
	}

	public function testEncodeFailureDisconnectsOnlyThatSession() : void{
		$sent = [];
		$session = $this->session(static function(NetworkSession $session, string $batch) use (&$sent) : void{
			$sent[] = $batch;
		});
		$rejected = false;
		$session->sendDataPacketWithReceipt(new SetTimePacket(PHP_INT_MAX))->onCompletion(
			static fn() => self::fail("Receipt of an unsent packet resolved"),
			static function() use (&$rejected) : void{ $rejected = true; }
		);

		$session->sendDataPacket(new SetTimePacket(1), true);

		self::assertFalse($session->isConnected());
		self::assertTrue($rejected);
		self::assertCount(1, $sent);
		$reader = new ByteBufferReader($sent[0]);
		self::assertSame(PacketId::DISCONNECT, Protocol::packetId($reader->readByteArray(VarInt::readUnsignedInt($reader))));
		self::assertSame(0, $reader->getUnreadLength());
	}

	public function testEncodeFailureDuringDisconnectStillClosesTheSession() : void{
		$sends = 0;
		$session = $this->session(static function() use (&$sends) : void{
			$sends++;
		});
		$session->sendDataPacket(new SetTimePacket(PHP_INT_MAX));

		$session->disconnect("test");

		self::assertFalse($session->isConnected());
		self::assertSame(0, $sends);
	}

	public function testNegativeWindowTypesEncode() : void{
		$batch = NetworkSession::encodeBatch([
			new ContainerOpenPacket(containerId: 1, containerType: WindowTypes::INVENTORY, position: new Vector3(0, 0, 0), targetActorId: 1),
			new ContainerClosePacket(containerId: 1, containerType: WindowTypes::NONE, serverInitiatedClose: true),
		]);
		self::assertStringContainsString("\x01\xff", $batch);
		self::assertStringContainsString("\x01\xf7", $batch);
	}
}
