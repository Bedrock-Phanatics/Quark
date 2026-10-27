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

	public function testDisconnectsWhenOnePacketIsTooLarge() : void{
		$session = $this->createMock(NetworkSession::class);
		$session->expects(self::once())->method("disconnectWithError");
		$this->interface($session)->putBatch(self::SESSION, self::packets(1, 1) . self::packets(1, 5 * 1024 * 1024), 0);
	}
}
