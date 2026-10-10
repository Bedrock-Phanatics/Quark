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
use function inflate_add;
use function inflate_get_status;
use function inflate_init;
use function libdeflate_deflate_compress;
use function strlen;
use function substr;
use function zlib_decode;
use function zlib_encode;
use const ZLIB_ENCODING_DEFLATE;
use const ZLIB_ENCODING_GZIP;
use const ZLIB_ENCODING_RAW;
use const ZLIB_STREAM_END;

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
			$this->checkDecompressionLimit($payload);
			throw new DecompressionException("Failed to decompress data");
		}
		if($this->maxDecompressionSize > 0 && strlen($result) > $this->maxDecompressionSize){
			throw new DecompressionException("Decompressed data exceeds the limit of {$this->maxDecompressionSize} bytes", reason: "decompression.limit", observed: strlen($result), limit: $this->maxDecompressionSize);
		}
		return $result;
	}

	private function checkDecompressionLimit(string $payload) : void{
		if($this->maxDecompressionSize <= 0){ return; }
		foreach([ZLIB_ENCODING_RAW, ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_GZIP] as $encoding){
			$context = inflate_init($encoding);
			if($context === false){ continue; }
			$size = 0;
			$length = strlen($payload);
			// Diagnose only failed decodes, with bounded temporary output.
			for($offset = 0; $offset < $length; $offset += 1024){
				$part = @inflate_add($context, substr($payload, $offset, 1024));
				if($part === false){ break; }
				if(strlen($part) > $this->maxDecompressionSize - $size){
					throw new DecompressionException("Decompressed data exceeds the limit of {$this->maxDecompressionSize} bytes", reason: "decompression.limit", observed: $size + strlen($part), limit: $this->maxDecompressionSize);
				}
				$size += strlen($part);
				if(inflate_get_status($context) === ZLIB_STREAM_END){ break; }
			}
		}
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
