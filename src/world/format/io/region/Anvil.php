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

namespace quark\world\format\io\region;

use quark\block\Block;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\world\format\PalettedBlockArray;
use quark\world\format\SubChunk;

class Anvil extends RegionWorldProvider{
	use LegacyAnvilChunkTrait;

	protected function deserializeSubChunk(CompoundTag $subChunk, PalettedBlockArray $biomes3d, \Logger $logger) : SubChunk{
		return new SubChunk(Block::EMPTY_STATE_ID, [$this->palettizeLegacySubChunkYZX(
			self::readFixedSizeByteArray($subChunk, "Blocks", 4096),
			self::readFixedSizeByteArray($subChunk, "Data", 2048),
			$logger
		)], $biomes3d);
		//ignore legacy light information
	}

	protected static function getRegionFileExtension() : string{
		return "mca";
	}

	protected static function getPcWorldFormatVersion() : int{
		return 19133;
	}

	public function getWorldMinY() : int{
		return 0;
	}

	public function getWorldMaxY() : int{
		//TODO: add world height options
		return 256;
	}
}
