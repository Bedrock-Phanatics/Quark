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

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;

/**
 * TODO: Implement trial spawner activation, mob spawning, cooldowns and reward ejection.
 */
class TrialSpawner extends Tile{
	use RetainedTagsTrait;

	private const RETAINED_TAGS = [
		"cooldown_ends_at" => LongTag::class,
		"current_mobs" => ListTag::class,
		"loot_tables_to_eject" => ListTag::class,
		"next_mob_spawns_at" => LongTag::class,
		"registered_players" => ListTag::class,
		"required_player_range" => IntTag::class,
		"simultaneous_mobs" => FloatTag::class,
		"simultaneous_mobs_added_per_player" => FloatTag::class,
		"spawn_data" => CompoundTag::class,
		"spawn_potentials" => ListTag::class,
		"spawn_range" => IntTag::class,
		"target_cooldown_length" => IntTag::class,
		"ticks_between_spawn" => IntTag::class,
		"total_mobs" => FloatTag::class,
		"total_mobs_added_per_player" => FloatTag::class,
	];

	public function readSaveData(CompoundTag $nbt) : void{
		$this->loadRetainedTags($nbt, self::RETAINED_TAGS);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->saveRetainedTags($nbt);
	}
}
