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

use Bedwire\PacketException;
use Bedwire\PacketId;
use Bedwire\Protocol;
use PHPUnit\Framework\TestCase;
use quark\network\mcpe\protocol\packet\SetTimePacket;
use quark\utils\Utils;
use function hash;
use function is_subclass_of;
use function json_encode;
use function str_replace;
use function ucwords;
use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;

final class PacketTest extends TestCase{

	public function testGeneratedCodeMatchesTheLoadedProtocol() : void{
		$hash = hash("sha256", json_encode(Protocol::schema(), JSON_THROW_ON_ERROR));
		self::assertSame(Protocol::VERSION, Packets::PROTOCOL);
		self::assertSame($hash, Packets::SCHEMA_HASH, "Run composer update-codegen after updating ext-bedwire");

		$packets = Protocol::schema()["packets"];
		self::assertIsArray($packets);
		foreach(Utils::promoteKeys($packets) as $name => $_){
			$name = (string) $name;
			$class = ProtocolClasses::BINDINGS[$name] ?? self::fail("$name has no packet class");
			self::assertTrue(is_subclass_of($class, Packet::class));
			self::assertSame(Protocol::id($name), (new \ReflectionClass($class))->getConstant("ID"));
		}

		foreach(Packets::HANDLERS as $id => $handler){
			$name = Protocol::name($id) ?? self::fail("Unknown packet $id");
			self::assertSame("handle" . str_replace("_", "", ucwords($name, "_")), $handler);
			self::assertNotSame(0, Protocol::direction($id) & Protocol::SERVERBOUND);
		}
		self::assertArrayNotHasKey(PacketId::START_GAME, Packets::HANDLERS);

		Protocol::bind([]);
		Protocol::bind(ProtocolClasses::BINDINGS);
	}

	public function testEncodedPacketsDecodeANewCopyEachTime() : void{
		$encoded = EncodedPacket::of(new SetTimePacket(1234));
		self::assertSame(PacketId::SET_TIME, $encoded->getId());
		self::assertSame("set_time", $encoded->getName());
		self::assertSame(Protocol::encode(PacketId::SET_TIME, ["time" => 1234]), $encoded->getBytes());

		$first = $encoded->getPacket();
		self::assertInstanceOf(SetTimePacket::class, $first);
		self::assertSame(1234, $first->time);
		$first->time = -1;
		$second = $encoded->getPacket();
		self::assertInstanceOf(SetTimePacket::class, $second);
		self::assertSame(1234, $second->time);
		self::assertSame($encoded->getBytes(), EncodedPacket::fromBytes($encoded->getBytes())->getBytes());
	}

	public function testMalformedPacketsAreRejected() : void{
		$this->expectException(PacketException::class);
		EncodedPacket::fromBytes(Protocol::encode(PacketId::SET_TIME, ["time" => 7]) . "\x00")->getPacket();
	}

	public function testUnknownPacketsCannotBeDecoded() : void{
		$packet = EncodedPacket::fromBytes("\xff\x07");
		self::assertSame(1023, $packet->getId());
		self::assertSame("unknown_1023", $packet->getName());
		$this->expectException(PacketException::class);
		$packet->getPacket();
	}

	public function testInvalidFieldsAreRejectedWhenEncoding() : void{
		$this->expectException(\ValueError::class);
		EncodedPacket::of(new SetTimePacket(PHP_INT_MAX));
	}
}
