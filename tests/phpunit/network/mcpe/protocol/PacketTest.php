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

namespace quark\network\mcpe\protocol;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\SetTimePacket;
use function substr;

final class PacketTest extends TestCase{

	public function testEncodedPacketsDecodeANewCopyEachTime() : void{
		$encoded = EncodedPacket::of(SetTimePacket::create(1234));
		self::assertSame(ProtocolInfo::SET_TIME_PACKET, $encoded->getId());
		self::assertSame("SetTimePacket", $encoded->getName());
		self::assertSame("\x0a\xa4\x13", $encoded->getBytes());

		$first = $encoded->getPacket();
		self::assertInstanceOf(SetTimePacket::class, $first);
		self::assertSame(1234, $first->time);
		$first->time = -1;
		$second = $encoded->getPacket();
		self::assertInstanceOf(SetTimePacket::class, $second);
		self::assertSame(1234, $second->time);
		self::assertSame($encoded->getBytes(), EncodedPacket::fromBytes($encoded->getBytes())->getBytes());
	}

	public function testTruncatedPacketsAreRejected() : void{
		$this->expectException(PacketDecodeException::class);
		EncodedPacket::fromBytes(substr(EncodedPacket::encode(SetTimePacket::create(1234)), 0, -1))->getPacket();
	}

	public function testUnknownPacketsCannotBeDecoded() : void{
		$packet = EncodedPacket::fromBytes("\xff\x07");
		self::assertSame(1023, $packet->getId());
		self::assertSame("unknown_1023", $packet->getName());
		$this->expectException(PacketDecodeException::class);
		$packet->getPacket();
	}

	public function testEmptyPacketsHaveNoHeader() : void{
		$this->expectException(PacketDecodeException::class);
		EncodedPacket::fromBytes("");
	}

	public function testOnlyClientboundPacketsAreClientbound() : void{
		self::assertTrue(EncodedPacket::isClientbound(ProtocolInfo::SET_TIME_PACKET));
		self::assertFalse(EncodedPacket::isClientbound(ProtocolInfo::REQUEST_CHUNK_RADIUS_PACKET));
		self::assertFalse(EncodedPacket::isClientbound(1023));
	}
}
