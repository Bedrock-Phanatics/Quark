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

namespace quark\block\tile;

use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\StringTag;

/**
 * The client draws the structure bounds from this data, so it is sent to viewers unchanged.
 * TODO: Implement the structure block UI, saving and loading structures, and redstone triggers.
 */
class StructureBlock extends Spawnable{
	use RetainedTagsTrait;

	private const RETAINED_TAGS = [
		"animationMode" => ByteTag::class,
		"animationSeconds" => FloatTag::class,
		"data" => IntTag::class,
		"dataField" => StringTag::class,
		"ignoreEntities" => ByteTag::class,
		"includePlayers" => ByteTag::class,
		"integrity" => FloatTag::class,
		"isPowered" => ByteTag::class,
		"mirror" => ByteTag::class,
		"redstoneSaveMode" => IntTag::class,
		"removeBlocks" => ByteTag::class,
		"rotation" => ByteTag::class,
		"seed" => LongTag::class,
		"showBoundingBox" => ByteTag::class,
		"structureName" => StringTag::class,
		"xStructureOffset" => IntTag::class,
		"yStructureOffset" => IntTag::class,
		"zStructureOffset" => IntTag::class,
		"xStructureSize" => IntTag::class,
		"yStructureSize" => IntTag::class,
		"zStructureSize" => IntTag::class,
	];

	public function readSaveData(CompoundTag $nbt) : void{
		$this->loadRetainedTags($nbt, self::RETAINED_TAGS);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->saveRetainedTags($nbt);
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$this->saveRetainedTags($nbt);
	}
}
