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
use function hash;
use function json_encode;
use function preg_replace;
use function str_replace;
use function strtolower;
use function ucwords;
use const JSON_THROW_ON_ERROR;

final class PacketTest extends TestCase{

	public function testGeneratedFactoriesMatchTheLoadedProtocol() : void{
		self::assertSame(Protocol::VERSION, Packets::PROTOCOL);
		self::assertSame(hash("sha256", json_encode(Protocol::schema(), JSON_THROW_ON_ERROR)), Packets::SCHEMA_HASH, "Run composer update-codegen after updating ext-bedwire");

		$methods = 0;
		foreach((new \ReflectionClass(Packets::class))->getMethods(\ReflectionMethod::IS_STATIC) as $method){
			$name = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $method->getName()));
			$packet = $method->invoke(null, []);
			self::assertInstanceOf(Packet::class, $packet);
			self::assertSame(Protocol::id($name), $packet->getId());
			$methods++;
		}
		$packets = Protocol::schema()["packets"];
		self::assertIsArray($packets);
		self::assertCount($methods, $packets);

		foreach(Packets::HANDLERS as $id => $handler){
			$name = Protocol::name($id) ?? self::fail("Unknown packet $id");
			self::assertSame("handle" . str_replace("_", "", ucwords($name, "_")), $handler);
			self::assertNotSame(0, Protocol::direction($id) & Protocol::SERVERBOUND);
		}
		self::assertArrayNotHasKey(PacketId::START_GAME, Packets::HANDLERS);
	}

	public function testFieldsAreEncodedOnlyWhenNeeded() : void{
		$packet = Packets::setTime(["time" => 1234]);
		self::assertFalse($packet->hasEncoded());
		self::assertSame([PacketId::SET_TIME, ["time" => 1234]], $packet->toBatchEntry());
		self::assertSame("set_time", $packet->getName());

		$encoded = $packet->getEncoded();
		self::assertTrue($packet->hasEncoded());
		self::assertSame($encoded, $packet->toBatchEntry());
		self::assertSame(["time" => 1234], Protocol::decode($encoded));

		$packet->setFields(["time" => -1]);
		self::assertFalse($packet->hasEncoded());
		self::assertSame(["time" => -1], Protocol::decode($packet->getEncoded()));
	}

	public function testEncodedPacketsAreDecodedOnlyWhenNeeded() : void{
		$encoded = Protocol::encode(PacketId::SET_TIME, ["time" => 7]);
		$packet = Packet::fromEncoded($encoded);
		self::assertSame(PacketId::SET_TIME, $packet->getId());
		self::assertSame($encoded, $packet->toBatchEntry());
		self::assertSame(["time" => 7], $packet->getFields());
		self::assertSame($encoded, $packet->getEncoded());
	}

	public function testMalformedPacketsAreRejected() : void{
		$this->expectException(PacketException::class);
		Packet::fromEncoded(Protocol::encode(PacketId::SET_TIME, ["time" => 7]) . "\x00")->getFields();
	}

	public function testUnknownPacketsCannotBeDecoded() : void{
		$packet = Packet::fromEncoded("\xff\x07");
		self::assertSame(1023, $packet->getId());
		self::assertSame("unknown_1023", $packet->getName());
		$this->expectException(PacketException::class);
		$packet->getFields();
	}

	public function testInvalidFieldsAreRejectedWhenEncoding() : void{
		$this->expectException(\ValueError::class);
		Packet::fromFields(PacketId::SET_TIME, ["time" => "soon"])->getEncoded();
	}
}
