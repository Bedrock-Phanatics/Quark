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
use pmmp\encoding\ByteBufferReader;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\PacketPool;
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
 * Feeds a corpus of valid packets, and seeded mutations of it, through BedrockProtocol the way the server decodes
 * them. The corpus came from protocol-zig, an independent implementation, so it also pins the wire format.
 * Set QUARK_FUZZ_ROUNDS for a longer run and QUARK_FUZZ_SEED for other mutations.
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
			$bytes = hex2bin(explode(" ", $line, 3)[2]);
			self::assertNotFalse($bytes);
			$packets[] = $bytes;
		}
		return $packets;
	}

	/**
	 * Decodes the bytes like a session does and encodes the result again. Anything but a PacketDecodeException
	 * escaping here would take the server down instead of the client.
	 *
	 * @return string|null the re-encoded bytes, or null if the bytes do not decode
	 */
	private static function reencode(string $bytes) : ?string{
		try{
			$packet = PacketPool::getInstance()->getPacketById(EncodedPacket::idOf($bytes));
			if($packet === null){
				return null;
			}
			$in = new ByteBufferReader($bytes);
			$packet->decode($in);
			if($in->getUnreadLength() > 0){
				return null;
			}
		}catch(PacketDecodeException){
			return null;
		}
		return EncodedPacket::encode($packet);
	}

	/**
	 * Anything that decodes must encode again, and that encoding must be stable.
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

	/**
	 * The corpus fills fields with arbitrary values, so BedrockProtocol may refuse some, such as an unknown enum case,
	 * or normalize them when encoding again.
	 */
	public function testCorpusDecodesAndEncodesStably() : void{
		$packets = self::corpus();
		self::assertGreaterThan(300, count($packets));
		$exact = 0;
		foreach($packets as $bytes){
			if(self::roundTrip($bytes) && self::reencode($bytes) === $bytes){
				++$exact;
			}
		}
		self::assertGreaterThanOrEqual(290, $exact);
	}

	public function testMutatedPacketsAreRejectedOrRoundTrip() : void{
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
