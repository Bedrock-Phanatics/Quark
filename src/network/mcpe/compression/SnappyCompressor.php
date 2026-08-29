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

namespace quark\network\mcpe\compression;

use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use quark\utils\Utils;
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

	public function getCompressionThreshold() : ?int {
		return $this->minCompressionSize;
	}

	/* @throws DecompressionException */
	private function readUncompressedLength(string $payload) : int {
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

	public function decompress(string $payload) : string {
		$expectedLength = $this->readUncompressedLength($payload);
		$result = @snappy_uncompress($payload);
		if ($result === false || strlen($result) !== $expectedLength) {
			throw new DecompressionException("Failed to decompress data");
		}
		return $result;
	}

	public function compress(string $payload) : string {
		return Utils::assumeNotFalse(snappy_compress($payload), "Snappy compression failed");
	}

	public function getNetworkId() : int {
		return CompressionAlgorithm::SNAPPY;
	}
}
