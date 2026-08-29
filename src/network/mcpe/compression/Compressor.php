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

interface Compressor{
	/**
	 * @throws DecompressionException
	 */
	public function decompress(string $payload) : string;

	public function compress(string $payload) : string;

	/**
	 * Returns the canonical ID of this compressor, used to tell the remote end how to decompress a packet compressed
	 * with this compressor.
	 *
	 * @return CompressionAlgorithm::*
	 */
	public function getNetworkId() : int;

	/**
	 * Returns the minimum size of packet batch that the compressor will attempt to compress.
	 *
	 * The compressor's output **MUST** still be valid input for the decompressor even if the compressor input is
	 * below this threshold.
	 * However, it may choose to use a cheaper compression option (e.g. zlib level 0, which simply wraps the data and
	 * doesn't attempt to compress it) to avoid wasting CPU time.
	 */
	public function getCompressionThreshold() : ?int;
}
