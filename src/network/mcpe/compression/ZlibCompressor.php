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
use quark\utils\SingletonTrait;
use quark\utils\Utils;
use function function_exists;
use function libdeflate_deflate_compress;
use function strlen;
use function zlib_decode;
use function zlib_encode;
use const ZLIB_ENCODING_RAW;

final class ZlibCompressor implements Compressor{
	use SingletonTrait;

	public const DEFAULT_LEVEL = 7;
	public const DEFAULT_THRESHOLD = 256;
	public const DEFAULT_MAX_DECOMPRESSION_SIZE = 8 * 1024 * 1024;

	/**
	 * @see SingletonTrait::make()
	 */
	private static function make() : self{
		return new self(self::DEFAULT_LEVEL, self::DEFAULT_THRESHOLD, self::DEFAULT_MAX_DECOMPRESSION_SIZE);
	}

	public function __construct(
		private int $level,
		private ?int $minCompressionSize,
		private int $maxDecompressionSize
	){}

	public function getCompressionThreshold() : ?int{
		return $this->minCompressionSize;
	}

	/**
	 * @throws DecompressionException
	 */
	public function decompress(string $payload) : string{
		$result = @zlib_decode($payload, $this->maxDecompressionSize);
		if($result === false){
			throw new DecompressionException("Failed to decompress data");
		}
		return $result;
	}

	public function compress(string $payload) : string{
		$compressible = $this->minCompressionSize !== null && strlen($payload) >= $this->minCompressionSize;
		$level = $compressible ? $this->level : 0;

		return function_exists('libdeflate_deflate_compress') ?
			libdeflate_deflate_compress($payload, $level) :
			Utils::assumeNotFalse(zlib_encode($payload, ZLIB_ENCODING_RAW, $level), "ZLIB compression failed");
	}

	public function getNetworkId() : int{
		return CompressionAlgorithm::ZLIB;
	}
}
