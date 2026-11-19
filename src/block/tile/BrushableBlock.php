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
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\StringTag;

/**
 * TODO: Implement brushing progress, loot table rolls and dropping the buried item.
 */
class BrushableBlock extends Tile{
	use RetainedTagsTrait;

	private const RETAINED_TAGS = [
		"LootTable" => StringTag::class,
		"LootTableSeed" => IntTag::class,
		"item" => CompoundTag::class,
		"brush_count" => IntTag::class,
		"brush_direction" => ByteTag::class,
		"type" => StringTag::class,
	];

	public function readSaveData(CompoundTag $nbt) : void{
		$this->loadRetainedTags($nbt, self::RETAINED_TAGS);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->saveRetainedTags($nbt);
	}
}
