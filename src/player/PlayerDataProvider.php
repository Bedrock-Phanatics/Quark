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

namespace quark\player;

use pocketmine\nbt\tag\CompoundTag;
use Vow\Promise;

/**
 * Handles storage of player data. Implementations must treat player names in a case-insensitive manner.
 *
 * Operations may complete asynchronously so slow storage never blocks the main thread. The server runs operations for
 * the same player one at a time in call order, so implementations don't need to order them. Promises must be settled
 * on the main thread.
 */
interface PlayerDataProvider{

	/**
	 * Resolves with whether there are any data associated with the given player name.
	 *
	 * @return Promise<bool>
	 */
	public function hasData(string $name) : Promise;

	/**
	 * Resolves with the data associated with the given player name, or null if there is no data.
	 * Rejects with {@link CorruptPlayerDataException} if the data was damaged and has been set aside, the player then
	 * joins as a new player. Any other {@link PlayerDataLoadException} means the data may still be intact, so the load
	 * fails instead of risking an overwrite.
	 *
	 * @return Promise<?CompoundTag>
	 */
	public function loadData(string $name) : Promise;

	/**
	 * Saves data for the given player name. The caller won't modify the tag afterwards.
	 * Rejects with {@link PlayerDataSaveException} if the data can't be written.
	 *
	 * @return Promise<null>
	 */
	public function saveData(string $name, CompoundTag $data) : Promise;
}
