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

namespace quark\data\bedrock;

use quark\world\format\io\leveldb\ChunkVersion;
use quark\world\format\io\leveldb\SubChunkVersion;

/**
 * All version infos related to current Minecraft data version support
 * These are mostly related to world storage but may also influence network stuff
 */
final class WorldDataVersions{
	/**
	 * Bedrock version of the most recent backwards-incompatible change to blockstates.
	 *
	 * This is *NOT* the same as current game version. It should match the numbers in the
	 * newest blockstate upgrade schema used in BedrockBlockUpgradeSchema.
	 */
	public const BLOCK_STATES =
		(1 << 24) | //major
		(21 << 16) | //minor
		(60 << 8) | //patch
		(33); //revision

	public const CHUNK = ChunkVersion::v1_21_120;
	public const SUBCHUNK = SubChunkVersion::PALETTED_MULTI;

	public const STORAGE = 10;

	/**
	 * Highest NetworkVersion of Bedrock worlds currently supported by Quark.
	 *
	 * This may be lower than the current protocol version if Quark does not yet support features of the newer
	 * version. This allows the protocol to be updated independently of world format support.
	 */
	public const NETWORK = 2168;

	public const LAST_OPENED_IN = [
		1, //major
		26, //minor
		44, //patch
		3, //revision
		0 //is beta
	];
}
