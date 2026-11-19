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

namespace quark\world\metadata;

use PHPUnit\Framework\TestCase;
use function array_keys;
use function chr;
use function ord;
use function random_bytes;
use function str_repeat;
use function strlen;
use function substr;

final class ChunkMetadataTest extends TestCase{

	private const TYPE = Retention::BLOCK_TYPE->value;
	private const STATE = Retention::BLOCK_STATE->value;
	private const POSITION = Retention::POSITION->value;

	private static function sample() : ChunkMetadata{
		$metadata = new ChunkMetadata(7);
		$metadata->setValue("a:chunk", self::POSITION, "");
		$metadata->setValue("b:chunk", self::TYPE, "\x00\xff binary");
		$metadata->setBlockValue(ChunkMetadata::blockHash(0, -64, 0), "a:owner", self::TYPE, "alice");
		$metadata->setBlockValue(ChunkMetadata::blockHash(15, 319, 15), "a:owner", self::TYPE, "bob");
		$metadata->setBlockValue(ChunkMetadata::blockHash(15, 319, 15), "b:unknown.key/x", self::POSITION | MetadataKey::FLAG_NOT_COPYABLE | 0x80, random_bytes(300));
		$metadata->setBlockValue(ChunkMetadata::blockHash(3, -1, 9), "b:x", self::STATE, str_repeat("z", ChunkMetadata::MAX_VALUE_BYTES));
		return $metadata;
	}

	public function testRoundTrip() : void{
		$metadata = self::sample();
		$decoded = ChunkMetadata::decode($metadata->encode());
		self::assertSame($metadata->encode(), $decoded->encode());
		self::assertSame(7, $decoded->epoch);
		self::assertSame("\x00\xff binary", $decoded->getValue("b:chunk"));
		self::assertSame("", $decoded->getValue("a:chunk"));
		self::assertSame("alice", $decoded->getBlockValue(ChunkMetadata::blockHash(0, -64, 0), "a:owner"));
		self::assertSame(self::POSITION | MetadataKey::FLAG_NOT_COPYABLE | 0x80, $decoded->getFlags("b:unknown.key/x", ChunkMetadata::blockHash(15, 319, 15)), "flags this version doesn't know are kept");
		self::assertFalse($decoded->isDirty(), "a decoded record matches what is stored");
		self::assertTrue(ChunkMetadata::decode((new ChunkMetadata())->encode())->isEmpty());
	}

	public function testEveryTruncationIsDetected() : void{
		$encoded = self::sample()->encode();
		for($length = 0; $length < strlen($encoded); ++$length){
			try{
				ChunkMetadata::decode(substr($encoded, 0, $length));
				self::fail("truncation to $length bytes was accepted");
			}catch(MetadataException){
			}
		}
		$this->expectException(MetadataException::class);
		ChunkMetadata::decode($encoded . "\x00");
	}

	public function testCorruptionIsDetected() : void{
		$encoded = self::sample()->encode();
		for($i = 0; $i < strlen($encoded); $i += 7){
			$flipped = $encoded;
			$flipped[$i] = chr(ord($flipped[$i]) ^ 0x10);
			try{
				ChunkMetadata::decode($flipped);
				self::fail("a flipped bit at $i was accepted");
			}catch(MetadataException $e){
				self::assertNotSame("", $e->getMessage());
			}
		}
	}

	public function testGarbageNeverEscapesAsAnotherError() : void{
		$rejected = 0;
		for($i = 0; $i < 2000; ++$i){
			try{
				ChunkMetadata::decode(random_bytes($i % 64 + 1));
			}catch(MetadataException){
				++$rejected;
			}
		}
		self::assertSame(2000, $rejected, "random bytes almost never pass the checksum");
	}

	public function testRetention() : void{
		$hash = ChunkMetadata::blockHash(1, 2, 3);
		$metadata = new ChunkMetadata();
		$metadata->setBlockValue($hash, "a:pos", self::POSITION, "1");
		$metadata->setBlockValue($hash, "a:type", self::TYPE, "2");
		$metadata->setBlockValue($hash, "a:state", self::STATE, "3");
		$metadata->setBlockValue($hash, "a:future", 3, "4");

		$metadata->onBlockChanged($hash, false);
		self::assertNull($metadata->getBlockValue($hash, "a:state"));
		self::assertSame("2", $metadata->getBlockValue($hash, "a:type"));

		$metadata->onBlockChanged($hash, true);
		self::assertNull($metadata->getBlockValue($hash, "a:type"));
		self::assertSame("1", $metadata->getBlockValue($hash, "a:pos"));
		self::assertSame("4", $metadata->getBlockValue($hash, "a:future"), "an unknown retention never removes anything");
	}

	public function testSameKeyCanHaveDifferentPolicies() : void{
		$metadata = new ChunkMetadata();
		$metadata->setBlockValue(0, "a:key", self::TYPE | MetadataKey::FLAG_NOT_COPYABLE, "unique");
		$metadata->setBlockValue(1, "a:key", self::POSITION, "claim");
		$metadata->setValue("a:key", self::STATE, "chunk");
		$metadata = ChunkMetadata::decode($metadata->encode());
		self::assertSame([], $metadata->takeBlockValues(0, true));
		self::assertSame([], $metadata->takeBlockValues(1, false));
		$metadata->onBlockChanged(0, false);
		self::assertSame("unique", $metadata->getBlockValue(0, "a:key"));
		$metadata->onBlockChanged(0, true);
		$metadata->onBlockChanged(1, true);
		self::assertNull($metadata->getBlockValue(0, "a:key"));
		self::assertSame("claim", $metadata->getBlockValue(1, "a:key"));
		self::assertSame("chunk", $metadata->getValue("a:key"));
	}

	public function testTakeAndCopy() : void{
		$hash = ChunkMetadata::blockHash(1, 2, 3);
		$metadata = new ChunkMetadata();
		$metadata->setBlockValue($hash, "a:pos", self::POSITION, "1");
		$metadata->setBlockValue($hash, "a:type", self::TYPE, "2");
		$metadata->setBlockValue($hash, "a:unique", self::TYPE | MetadataKey::FLAG_NOT_COPYABLE, "3");

		self::assertSame(["a:type" => [self::TYPE, "2"]], $metadata->takeBlockValues($hash, true));
		self::assertSame("3", $metadata->getBlockValue($hash, "a:unique"), "copying leaves the source alone");

		$taken = $metadata->takeBlockValues($hash, false);
		self::assertSame(["a:type", "a:unique"], array_keys($taken));
		self::assertSame(["a:pos" => "1"], $metadata->getBlockValues($hash), "position-bound values stay");

		$other = ChunkMetadata::blockHash(4, 5, 6);
		$metadata->setBlockValue($other, "a:type", self::TYPE, "old");
		$metadata->setBlockValue($other, "a:pos2", self::POSITION, "kept");
		$metadata->putBlockValues($other, $taken);
		self::assertSame(["a:pos2" => "kept", "a:type" => "2", "a:unique" => "3"], $metadata->getBlockValues($other));
	}

	public function testPutRejectsNamesRecordsCantHold() : void{
		$metadata = new ChunkMetadata();
		$metadata->setBlockValue(0, "a:b", self::TYPE, "kept");
		$before = $metadata->encode();
		foreach([["nocolon", self::TYPE], [str_repeat("a", 254) . ":b", self::TYPE], ["a:c", 256]] as [$name, $flags]){
			try{
				$metadata->putBlockValues(0, [$name => [$flags, "x"]]);
				self::fail("$name was accepted");
			}catch(\InvalidArgumentException){
			}
		}
		self::assertSame($before, $metadata->encode(), "a rejected put changes nothing");
	}

	public function testLimits() : void{
		$metadata = new ChunkMetadata();
		try{
			$metadata->setBlockValue(0, "a:b", self::TYPE, str_repeat("x", ChunkMetadata::MAX_VALUE_BYTES + 1));
			self::fail("an oversized value was accepted");
		}catch(\InvalidArgumentException){
		}
		$value = str_repeat("x", ChunkMetadata::MAX_VALUE_BYTES);
		$stored = 0;
		try{
			for($i = 0; ; ++$i){
				$metadata->setBlockValue($i << 8, "a:b", self::TYPE, $value);
				++$stored;
			}
		}catch(\LengthException){
		}
		self::assertSame(15, $stored);
		$before = $metadata->encode();
		try{
			$metadata->putBlockValues(1 << 8, ["a:c" => [self::TYPE, $value], "a:d" => [self::TYPE, $value]]);
			self::fail("an oversized put was accepted");
		}catch(\LengthException){
		}
		self::assertSame($before, $metadata->encode(), "a rejected put changes nothing");

		$metadata->removeBlockValue(0, "a:b");
		$metadata->setBlockValue(1 << 8, "a:c", self::TYPE, $value);
		self::assertSame($value, $metadata->getBlockValue(1 << 8, "a:c"), "removals give their space back");
	}

	public function testKeyNamesCountTowardsTheSizeLimit() : void{
		$metadata = new ChunkMetadata();
		$prefix = "a:" . str_repeat("x", 180);
		try{
			for($i = 0; $i < 6000; ++$i){
				$metadata->setValue($prefix . $i, self::TYPE, "");
			}
			self::fail("key names exceeded the record budget");
		}catch(\LengthException){
		}
		self::assertLessThanOrEqual(ChunkMetadata::MAX_BYTES + 13, strlen($metadata->encode()));
	}

	public function testDirtyMarks() : void{
		$metadata = new ChunkMetadata();
		self::assertFalse($metadata->isDirty());
		$metadata->setValue("a:b", self::TYPE, "1");
		$mark = $metadata->getChanges();
		$metadata->setValue("a:b", self::TYPE, "1");
		self::assertSame($mark, $metadata->getChanges(), "writing the same value is not a change");
		$metadata->setValue("a:b", self::TYPE, "2");
		$metadata->markSaved($mark);
		self::assertTrue($metadata->isDirty(), "a change after the saved state stays dirty");
		$metadata->markSaved($metadata->getChanges());
		self::assertFalse($metadata->isDirty());
		self::assertFalse($metadata->removeValue("a:missing"));
		self::assertFalse($metadata->isDirty());
	}

	public function testRemoveNamespace() : void{
		$metadata = self::sample();
		self::assertFalse($metadata->removePurgedNamespaces(["a" => 7]));
		self::assertTrue($metadata->removePurgedNamespaces(["a" => 8]));
		self::assertNull($metadata->getValue("a:chunk"));
		self::assertNull($metadata->getBlockValue(ChunkMetadata::blockHash(0, -64, 0), "a:owner"));
		self::assertNotNull($metadata->getValue("b:chunk"));
		self::assertFalse($metadata->removePurgedNamespaces(["a" => 8]));
		self::assertFalse($metadata->removePurgedNamespaces(["ab" => 8]), "namespaces match whole");
	}
}
