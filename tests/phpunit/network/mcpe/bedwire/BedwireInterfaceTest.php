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
use quark\network\mcpe\NetworkSession;
use quark\Server;
use function array_fill_keys;
use function array_keys;
use function str_repeat;

final class BedwireInterfaceTest extends TestCase{
	private const int SESSION = 1;

	private BedwireServer $server;

	protected function setUp() : void{
		$this->server = new BedwireServer("127.0.0.1", 0, ["authentication" => false, "udp_mux" => false]);
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
		self::assertFalse($interface->putPackets(self::SESSION, ["\x01"]));
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

	public function testShutdownReleasesSendsStillQueued() : void{
		$interface = $this->deferredInterface($this->createMock(NetworkSession::class));
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
	}

	public function testDisconnectsWhenOnePacketIsTooLarge() : void{
		$session = $this->createMock(NetworkSession::class);
		$session->expects(self::once())->method("disconnectWithError");
		$this->interface($session)->putBatch(self::SESSION, self::packets(1, 1) . self::packets(1, 5 * 1024 * 1024), 0);
	}
}
