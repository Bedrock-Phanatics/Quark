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

use quark\block\RedstoneComparator;
use pocketmine\nbt\tag\CompoundTag;
use function max;
use function min;

/**
 * @deprecated
 * @see RedstoneComparator
 */
class Comparator extends Tile{
	private const TAG_OUTPUT_SIGNAL = "OutputSignal"; //int

	protected int $signalStrength = 0;

	public function getSignalStrength() : int{
		return $this->signalStrength;
	}

	public function setSignalStrength(int $signalStrength) : void{
		$this->signalStrength = min(15, max(0, $signalStrength));
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->setSignalStrength($nbt->getInt(self::TAG_OUTPUT_SIGNAL, 0));
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$nbt->setInt(self::TAG_OUTPUT_SIGNAL, $this->signalStrength);
	}
}
