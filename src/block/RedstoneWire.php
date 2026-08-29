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

namespace quark\block;

use quark\block\utils\AnalogRedstoneSignalEmitter;
use quark\block\utils\AnalogRedstoneSignalEmitterTrait;
use quark\block\utils\redstone\PowerSource;

use quark\block\utils\redstone\RedstoneWireBehavior;
use quark\block\utils\redstone\Transmittable;
use quark\item\Item;
use quark\item\VanillaItems;

class RedstoneWire extends Flowable implements AnalogRedstoneSignalEmitter, PowerSource, Transmittable{
	use RedstoneWireBehavior;
	use AnalogRedstoneSignalEmitterTrait;

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();
		//TODO: check connections to nearby redstone components

		return $this;
	}

	public function asItem() : Item{
		return VanillaItems::REDSTONE_DUST();
	}
}
