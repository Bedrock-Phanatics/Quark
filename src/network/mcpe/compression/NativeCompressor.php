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

use quark\network\mcpe\protocol\types\CompressionAlgorithm;

/**
 * For transports that compress natively, such as Bedwire: batches leave PHP uncompressed, while clients are still
 * told the algorithm the transport compresses with.
 */
final class NativeCompressor implements Compressor{

	public function __construct(
		private CompressionAlgorithm $networkType
	){}

	public function decompress(string $payload) : string{
		throw new DecompressionException("Batches from native transports arrive already decompressed", reason: "compression.native");
	}

	public function compress(string $payload) : string{
		return $payload;
	}

	public function getNetworkType() : CompressionAlgorithm{
		return $this->networkType;
	}

	/**
	 * Null makes prepareBatch() frame every batch as uncompressed, which the transport then compresses itself.
	 */
	public function getCompressionThreshold() : ?int{
		return null;
	}
}
