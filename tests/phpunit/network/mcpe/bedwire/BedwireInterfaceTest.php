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

use Bedwire\Server as BedwireServer;
use PHPUnit\Framework\TestCase;
use pmmp\encoding\VarInt;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use quark\network\mcpe\compression\Compressor;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\EntityEventBroadcaster;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\PacketBroadcaster;
use quark\network\NetworkInterfaceStartException;
use quark\player\GameMode;
use quark\Server;
use quark\ServerConfigGroup;
use quark\utils\Config;
use function array_fill_keys;
use function array_keys;
use function str_repeat;

final class BedwireInterfaceTest extends TestCase{
	private const int SESSION = 1;

	private BedwireServer $server;

	protected function setUp() : void{
		$this->server = new BedwireServer("127.0.0.1", 0, ["authentication" => false, "udp_mux" => false, "protocol_version" => ProtocolInfo::CURRENT_PROTOCOL, "minecraft_version" => ProtocolInfo::MINECRAFT_VERSION_NETWORK]);
	}

	protected function tearDown() : void{
		$this->server->stop();
	}

	private function interface(NetworkSession $session) : BedwireInterface{
		$interface = new \ReflectionClass(BedwireInterface::class)->newInstanceWithoutConstructor();
		new \ReflectionProperty(BedwireInterface::class, "bedwire")->setValue($interface, $this->server);
		new \ReflectionProperty(BedwireInterface::class, "sessions")->setValue($interface, [self::SESSION => $session]);
		return $interface;
	}

	public function testProxyOnlyRejectsARegularVerifiedTransportBeforeCreatingSession() : void{
		$server = new \ReflectionClass(Server::class)->newInstanceWithoutConstructor();
		new \ReflectionProperty(Server::class, "proxyOnly")->setValue($server, true);
		new \ReflectionProperty(Server::class, "trustedProxyKeys")->setValue($server, ["proxy public key"]);
		$interface = new \ReflectionClass(BedwireInterface::class)->newInstanceWithoutConstructor();
		new \ReflectionProperty(BedwireInterface::class, "server")->setValue($interface, $server);
		new \ReflectionProperty(BedwireInterface::class, "bedwire")->setValue($interface, $this->server);

		new \ReflectionMethod(BedwireInterface::class, "openSession")->invoke($interface, self::SESSION, "127.0.0.1:19132", "1", str_repeat("x", 32), true);

		self::assertSame([], new \ReflectionProperty(BedwireInterface::class, "sessions")->getValue($interface));
	}

	private static function packets(int $count, int $size) : string{
		return str_repeat(VarInt::packUnsignedInt($size) . str_repeat("a", $size), $count);
	}

	/**
	 * @return \Generator<string, array{string, int}>
	 */
	public static function oversizedBatchProvider() : \Generator{
		yield "more packets than one native batch takes" => [self::packets(1500, 1), 0];
		yield "more packets after the compression byte" => ["\xff" . self::packets(2049, 1), 1];
		yield "more bytes than one native batch takes" => [self::packets(3, 3 * 1024 * 1024), 0];
	}

	/**
	 * @dataProvider oversizedBatchProvider
	 */
	public function testSplitsBatchesTooLargeToSendAtOnce(string $batch, int $offset) : void{
		$session = $this->createMock(NetworkSession::class);
		$session->expects(self::never())->method("disconnectWithError");
		$this->interface($session)->putBatch(self::SESSION, $batch, $offset);
	}

	public function testOversizedLoginKeyOnlyDisconnectsItsSession() : void{
		$session = $this->createMock(NetworkSession::class);
		$session->expects(self::once())->method("disconnectWithError");
		$this->interface($session)->acceptLogin(self::SESSION, str_repeat("k", 600));
	}

	public function testSendsQueueBehindDeferredOnesUntilFlushed() : void{
		$session = $this->createMock(NetworkSession::class);
		$session->expects(self::never())->method("disconnectWithError");
		$interface = $this->interface($session);
		$server = $this->createMock(Server::class);
		$server->method("getTick")->willReturn(10);
		new \ReflectionProperty(BedwireInterface::class, "server")->setValue($interface, $server);

		$sent = [];
		$defer = new \ReflectionMethod(BedwireInterface::class, "defer");
		$defer->invoke($interface, self::SESSION, 4, function() use (&$sent) : void{ $sent[] = "first"; });
		$interface->putBatch(self::SESSION, self::packets(2, 3), 0);
		$deferred = new \ReflectionProperty(BedwireInterface::class, "deferred");
		/** @var array<int, list<mixed>> $queued */
		$queued = $deferred->getValue($interface);
		self::assertCount(2, $queued[self::SESSION]);

		new \ReflectionMethod(BedwireInterface::class, "flushDeferred")->invoke($interface);
		self::assertSame(["first"], $sent);
		self::assertSame([], $deferred->getValue($interface));
		self::assertSame(0, new \ReflectionProperty(BedwireInterface::class, "deferredBytes")->getValue($interface));
	}

	private function deferredInterface(NetworkSession $session) : BedwireInterface{
		$interface = $this->interface($session);
		$server = $this->createMock(Server::class);
		$server->method("getTick")->willReturn(10);
		new \ReflectionProperty(BedwireInterface::class, "server")->setValue($interface, $server);
		return $interface;
	}

	/**
	 * @phpstan-param array<int, list<array{int, \Closure() : void}>> $deferred
	 */
	private static function flushDeferred(BedwireInterface $interface, array $deferred) : int{
		$bytes = 0;
		foreach($deferred as $queue){
			foreach($queue as [$size]){
				$bytes += $size;
			}
		}
		new \ReflectionProperty(BedwireInterface::class, "deferred")->setValue($interface, $deferred);
		new \ReflectionProperty(BedwireInterface::class, "deferredSince")->setValue($interface, array_fill_keys(array_keys($deferred), 10));
		new \ReflectionProperty(BedwireInterface::class, "deferredBytes")->setValue($interface, $bytes);
		new \ReflectionMethod(BedwireInterface::class, "flushDeferred")->invoke($interface);
		$left = new \ReflectionProperty(BedwireInterface::class, "deferredBytes")->getValue($interface);
		self::assertIsInt($left);
		return $left;
	}

	public function testFailedDeferredSendReleasesEverythingQueuedBehindIt() : void{
		$session = $this->createMock(NetworkSession::class);
		$session->expects(self::once())->method("disconnectWithError");
		self::assertSame(0, self::flushDeferred($this->deferredInterface($session), [self::SESSION => [
			[100, function() : void{ throw new \ValueError("too large"); }],
			[200, function() : void{}],
			[300, function() : void{}],
		]]));
	}

	public function testDeferredQueueDroppedDuringFlushIsSkipped() : void{
		$interface = $this->deferredInterface($this->createMock(NetworkSession::class));
		$ran = false;
		self::assertSame(0, self::flushDeferred($interface, [
			self::SESSION => [[10, function() use ($interface) : void{
				new \ReflectionMethod(BedwireInterface::class, "discardDeferred")->invoke($interface, 2);
			}]],
			2 => [[20, function() use (&$ran) : void{ $ran = true; }]],
		]));
		self::assertFalse($ran);
	}

	public function testBlockedSendLeavesFollowingCommandsQueuedAndExpiresStaleSessions() : void{
		$session = $this->createMock(NetworkSession::class);
		$interface = $this->deferredInterface($session);
		$staleSession = $this->createMock(NetworkSession::class);
		$staleSession->expects(self::once())->method("disconnectWithError")->with("Network send queue is stuck");
		new \ReflectionProperty(BedwireInterface::class, "sessions")->setValue($interface, [self::SESSION => $session, 2 => $staleSession]);
		$server = $this->createMock(Server::class);
		$server->method("getTick")->willReturn(200);
		new \ReflectionProperty(BedwireInterface::class, "server")->setValue($interface, $server);

		$defer = new \ReflectionMethod(BedwireInterface::class, "defer");
		$retry = function() use ($interface, $defer) : void{
			$defer->invoke($interface, self::SESSION, 4, function() : void{});
		};
		$following = function() : void{ self::fail("Subsequent sends must wait for the blocked command"); };
		$defer->invoke($interface, self::SESSION, 4, $retry);
		$defer->invoke($interface, self::SESSION, 4, $following);
		$defer->invoke($interface, 2, 4, $following);
		new \ReflectionProperty(BedwireInterface::class, "deferredSince")->setValue($interface, [self::SESSION => 200, 2 => 99]);

		new \ReflectionMethod(BedwireInterface::class, "flushDeferred")->invoke($interface);
		/** @var array<int, list<array{int, \Closure() : void}>> $deferred */
		$deferred = new \ReflectionProperty(BedwireInterface::class, "deferred")->getValue($interface);
		self::assertSame([self::SESSION], array_keys($deferred));
		self::assertCount(2, $deferred[self::SESSION]);
		self::assertSame($following, $deferred[self::SESSION][1][1]);
		self::assertSame(2056, new \ReflectionProperty(BedwireInterface::class, "deferredBytes")->getValue($interface));
	}

	public function testShutdownReleasesSendsStillQueued() : void{
		$session = $this->createMock(NetworkSession::class);
		$session->expects(self::once())->method("onClientDisconnect")->with("Network shutting down");
		$interface = $this->deferredInterface($session);
		$defer = new \ReflectionMethod(BedwireInterface::class, "defer");
		//keeps finding the ring full, like a send during a mass kick at shutdown
		$retry = function() use (&$retry, $interface, $defer) : void{
			$defer->invoke($interface, self::SESSION, 4, $retry);
		};
		$defer->invoke($interface, self::SESSION, 4, $retry);

		$interface->shutdown();

		self::assertSame([], new \ReflectionProperty(BedwireInterface::class, "deferred")->getValue($interface));
		self::assertSame(0, new \ReflectionProperty(BedwireInterface::class, "deferredBytes")->getValue($interface));
		self::assertNull(new \ReflectionProperty(BedwireInterface::class, "bedwire")->getValue($interface));
		self::assertSame([], new \ReflectionProperty(BedwireInterface::class, "sessions")->getValue($interface));
		$interface->shutdown();
	}

	public function testFailedStartupReleasesNativeServer() : void{
		$server = $this->createMock(Server::class);
		$server->method("getMotd")->willReturn("test");
		$server->method("getName")->willReturn("test");
		$server->method("getGamemode")->willReturn(GameMode::SURVIVAL);
		$server->method("getTickSleeper")->willThrowException(new \RuntimeException("Tick sleeper unavailable"));
		$interface = new BedwireInterface($server, "127.0.0.1", 0, [
			"authentication" => false,
			"protocol_version" => ProtocolInfo::CURRENT_PROTOCOL,
			"minecraft_version" => ProtocolInfo::MINECRAFT_VERSION_NETWORK
		],
			$this->createMock(Compressor::class),
			$this->createMock(PacketBroadcaster::class),
			$this->createMock(EntityEventBroadcaster::class),
			$this->createMock(TypeConverter::class)
		);
		$this->expectException(NetworkInterfaceStartException::class);
		$this->expectExceptionMessage("Tick sleeper unavailable");
		try{
			$interface->start();
		}finally{
			self::assertNull(new \ReflectionProperty(BedwireInterface::class, "bedwire")->getValue($interface));
		}
	}

	public function testProxyOnlyUsesGlobalConnectionLimitsAndDisablesLanDiscovery() : void{
		$config = self::createStub(Config::class);
		$server = $this->createMock(Server::class);
		$server->method("getConfigGroup")->willReturn(new ServerConfigGroup($config, $config));
		$server->method("getDataPath")->willReturn(__DIR__);
		$server->method("getIp")->willReturn("0.0.0.0");
		$server->method("getMaxPlayers")->willReturn(50);
		$server->method("isProxyOnly")->willReturnOnConsecutiveCalls(false, true);
		$transport = new BedwireTransport($server);
		$options = new \ReflectionMethod(BedwireTransport::class, "options");
		/** @var array<string, mixed> $standalone */
		$standalone = $options->invoke($transport);
		self::assertArrayNotHasKey("max_negotiations_per_address", $standalone);
		self::assertArrayNotHasKey("max_connections_per_address", $standalone);
		self::assertSame(7551, $standalone["lan_port"]);
		/** @var array<string, mixed> $proxied */
		$proxied = $options->invoke($transport);
		self::assertSame(0, $proxied["max_negotiations_per_address"]);
		self::assertSame(0, $proxied["max_connections_per_address"]);
		self::assertSame(0, $proxied["lan_port"]);
		self::assertSame(116, $proxied["max_sessions"]);
	}

	public function testDisconnectsWhenOnePacketIsTooLarge() : void{
		$session = $this->createMock(NetworkSession::class);
		$session->expects(self::once())->method("disconnectWithError");
		$this->interface($session)->putBatch(self::SESSION, self::packets(1, 1) . self::packets(1, 5 * 1024 * 1024), 0);
	}
}
