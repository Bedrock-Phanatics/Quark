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
 * A player data save that hasn't started yet. Saves requested before it starts replace its data instead of queueing.
 * @internal
 */
final class QueuedPlayerSave{
	/** @var Promise<mixed>|null the player's last queued operation, which is this save until another is queued */
	public ?Promise $tail = null;
	/** @var Promise<null>|null */
	public ?Promise $result = null;

	public function __construct(
		public CompoundTag $data
	){}
}
