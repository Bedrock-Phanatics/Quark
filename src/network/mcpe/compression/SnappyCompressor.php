<?php

/*
 *   ____  _   _    _    ____  _  __
 *  / __ \| | | |  / \  |  _ \| |/ /
 * | |  | | | | | / _ \ | |_) | ' /
 * | |__| | |_| |/ ___ \|  _ <| . \
 *  \___\_\\___//_/   \_\_| \_\_|\_\
 *
 * Quark - Performance without compromise.
 *
 * A high-performance fork of PocketMine-MP for Minecraft: Bedrock Edition.
 *
 * Licensed under the GNU Lesser General Public License v3.0 or later.
 *
 * @link https://github.com/Bedrock-Phanatics/Quark
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\compression;

use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use pocketmine\utils\Utils;
use function ord;
use function snappy_compress;
use function snappy_uncompress;
use function strlen;

final class SnappyCompressor implements Compressor{
	public const DEFAULT_MAX_DECOMPRESSION_SIZE = 8 * 1024 * 1024;

	public function __construct(
		private ?int $minCompressionSize,
		private int $maxDecompressionSize
	){}

	public function getCompressionThreshold(): ?int {
		return $this->minCompressionSize;
	}

	/* @throws DecompressionException */
	private function readUncompressedLength(string $payload): int {
		$result = 0;
		$shift = 0;
		$length = strlen($payload);
		for ($offset = 0; $offset < $length && $offset < 5; ++$offset) {
			$byte = ord($payload[$offset]);
			$result |= ($byte & 0x7f) << $shift;
			if (($byte & 0x80) === 0) {
				if ($result > $this->maxDecompressionSize) {
					throw new DecompressionException("Decompressed data exceeds the limit of {$this->maxDecompressionSize} bytes");
				}
				return $result;
			}
			$shift += 7;
		}

		throw new DecompressionException("Invalid Snappy uncompressed length");
	}

	public function decompress(string $payload): string {
		$expectedLength = $this->readUncompressedLength($payload);
		$result = @snappy_uncompress($payload);
		if ($result === false || strlen($result) !== $expectedLength) {
			throw new DecompressionException("Failed to decompress data");
		}
		return $result;
	}

	public function compress(string $payload): string {
		return Utils::assumeNotFalse(snappy_compress($payload), "Snappy compression failed");
	}

	public function getNetworkId(): int {
		return CompressionAlgorithm::SNAPPY;
	}
}
