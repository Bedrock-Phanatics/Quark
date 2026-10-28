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
use Bedwire\Protocol;
use PHPUnit\Framework\TestCase;
use function bin2hex;
use function chr;
use function count;
use function explode;
use function file;
use function getenv;
use function hex2bin;
use function intdiv;
use function is_numeric;
use function memory_get_usage;
use function mt_rand;
use function mt_srand;
use function ord;
use function strlen;
use function substr;
use const FILE_IGNORE_NEW_LINES;
use const MT_RAND_MT19937;

/**
 * Feeds protocol-zig's corpus, and seeded mutations of it, through the bound packet classes the server decodes into.
 * The corpus is a copy of protocol-zig's tests/corpus.txt. Set QUARK_FUZZ_ROUNDS for a longer run and QUARK_FUZZ_SEED
 * for other mutations.
 */
final class PacketFuzzTest extends TestCase{
	private const MAX_RETAINED_BYTES = 64 * 1024 * 1024;

	/**
	 * @return list<string>
	 */
	private static function corpus() : array{
		$lines = file(__DIR__ . "/golden/corpus.txt", FILE_IGNORE_NEW_LINES);
		self::assertNotFalse($lines);
		$packets = [];
		foreach($lines as $line){
			if($line === "" || $line[0] === "#"){
				continue;
			}
			$bytes = hex2bin(explode(" ", $line)[2]);
			self::assertNotFalse($bytes);
			$packets[] = $bytes;
		}
		return $packets;
	}

	/**
	 * Decodes into the bound classes and encodes the result again, keeping the sub-client IDs only the header carries.
	 *
	 * @return string|null the re-encoded bytes, or null if the bytes do not decode
	 */
	private static function reencode(string $bytes) : ?string{
		try{
			$packet = Protocol::decode($bytes, EncodedPacket::MAX_DECODED_VALUES);
		}catch(PacketException){
			return null;
		}
		if($packet === null){
			return null;
		}
		self::assertInstanceOf(Packet::class, $packet, "Decoded " . bin2hex(substr($bytes, 0, 8)) . " into an array");
		[, $sender, $target] = Protocol::header($bytes);
		return Protocol::encode($packet->getId(), $packet, $sender, $target);
	}

	/**
	 * Anything that decodes must encode again, and that encoding must be stable. It may differ from the input only
	 * where PHP itself normalizes a value, such as a signalling NaN becoming a quiet one.
	 *
	 * @return bool whether the bytes decoded
	 */
	private static function roundTrip(string $bytes) : bool{
		$encoded = self::reencode($bytes);
		if($encoded === null){
			return false;
		}
		self::assertSame(bin2hex($encoded), bin2hex(self::reencode($encoded) ?? ""), "Re-encoding " . bin2hex(substr($bytes, 0, 8)) . " is not stable");
		return true;
	}

	private static function mutate(string $bytes) : string{
		$length = strlen($bytes);
		$at = mt_rand(0, $length - 1);
		return match(mt_rand(0, 4)){
			0 => substr($bytes, 0, $at) . chr(ord($bytes[$at]) ^ (1 << mt_rand(0, 7))) . substr($bytes, $at + 1),
			1 => substr($bytes, 0, $at) . chr(mt_rand(0, 255)) . substr($bytes, $at + 1),
			2 => substr($bytes, 0, $at),
			3 => substr($bytes, 0, $at) . chr(mt_rand(0, 255)) . substr($bytes, $at),
			//repeating a span stretches lengths and counts into the bytes that follow
			default => substr($bytes, 0, $at) . substr($bytes, intdiv($at, 2), mt_rand(1, 16)) . substr($bytes, $at),
		};
	}

	public function testCorpusRoundTripsThroughBoundClasses() : void{
		ProtocolClasses::bind();
		$packets = self::corpus();
		self::assertGreaterThan(300, count($packets));
		foreach($packets as $bytes){
			self::assertSame(bin2hex($bytes), bin2hex(self::reencode($bytes) ?? ""), "Corpus packet " . bin2hex(substr($bytes, 0, 8)) . " did not round trip");
		}
	}

	public function testMutatedPacketsAreRejectedOrRoundTrip() : void{
		ProtocolClasses::bind();
		$rounds = getenv("QUARK_FUZZ_ROUNDS");
		$rounds = is_numeric($rounds) ? (int) $rounds : 40;
		$seed = getenv("QUARK_FUZZ_SEED");
		mt_srand(is_numeric($seed) ? (int) $seed : 0x5eed, MT_RAND_MT19937);
		$base = memory_get_usage();
		$decoded = 0;
		$rejected = 0;
		foreach(self::corpus() as $bytes){
			for($i = 0; $i < $rounds; ++$i){
				$mutated = self::mutate($bytes);
				for($depth = mt_rand(0, 2); $depth > 0 && $mutated !== ""; --$depth){
					$mutated = self::mutate($mutated);
				}
				if($mutated === ""){
					continue;
				}
				self::roundTrip($mutated) ? ++$decoded : ++$rejected;
				self::assertLessThan(self::MAX_RETAINED_BYTES, memory_get_usage() - $base);
			}
		}
		self::assertGreaterThan(0, $decoded);
		self::assertGreaterThan(0, $rejected);
	}
}
