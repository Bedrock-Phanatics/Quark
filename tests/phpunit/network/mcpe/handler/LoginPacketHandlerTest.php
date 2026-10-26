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

use PHPUnit\Framework\TestCase;
use quark\network\PacketHandlingException;

class LoginPacketHandlerTest extends TestCase{

	public function testParsesProxiedPlayerAddresses() : void{
		self::assertSame(["203.0.113.5", 51234], LoginPacketHandler::parsePlayerAddress("203.0.113.5:51234"));
		self::assertSame(["2001:db8::1", 19132], LoginPacketHandler::parsePlayerAddress("[2001:db8::1]:19132"));
		self::assertSame(["fe80::1", 1], LoginPacketHandler::parsePlayerAddress("[fe80::1%3]:1"));
	}

	/**
	 * @return string[][]|int[][]
	 */
	public static function invalidAddressProvider() : array{
		return [[5], ["203.0.113.5"], ["203.0.113.5:"], ["203.0.113.5:65536"], ["203.0.113.5:-1"], ["2001:db8::1:5"], ["example.com:5"], ["[::1]:123456"], ["1.2.3.4:5\n"]];
	}

	/**
	 * @dataProvider invalidAddressProvider
	 */
	public function testRejectsMalformedAddresses(mixed $address) : void{
		$this->expectException(\InvalidArgumentException::class);
		LoginPacketHandler::parsePlayerAddress($address);
	}

	public function testParsesConnectionRequests() : void{
		self::assertSame(["{}", "a.b.c"], LoginPacketHandler::parseConnectionRequest("\x02\x00\x00\x00{}\x05\x00\x00\x00a.b.c"));
		self::assertSame(["", ""], LoginPacketHandler::parseConnectionRequest("\x00\x00\x00\x00\x00\x00\x00\x00"));
	}

	/**
	 * @return string[][]
	 */
	public static function invalidConnectionRequestProvider() : array{
		return [[""], ["\x02\x00\x00"], ["\x02\x00\x00\x00{}"], ["\x03\x00\x00\x00{}\x00\x00\x00\x00"], ["\x00\x00\x00\x00\xff\xff\xff\xff"], ["\x00\x00\x00\x00\xff\xff\xff\x7f"]];
	}

	/**
	 * @dataProvider invalidConnectionRequestProvider
	 */
	public function testRejectsMalformedConnectionRequests(string $request) : void{
		$this->expectException(PacketHandlingException::class);
		LoginPacketHandler::parseConnectionRequest($request);
	}
}
