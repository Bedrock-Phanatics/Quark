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

use quark\block\Note as BlockNote;
use pocketmine\nbt\tag\CompoundTag;

/**
 * @deprecated
 */
class Note extends Tile{
	private int $pitch = 0;

	public function readSaveData(CompoundTag $nbt) : void{
		if(($pitch = $nbt->getByte("note", $this->pitch)) > BlockNote::MIN_PITCH && $pitch <= BlockNote::MAX_PITCH){
			$this->pitch = $pitch;
		}
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$nbt->setByte("note", $this->pitch);
	}

	public function getPitch() : int{
		return $this->pitch;
	}

	public function setPitch(int $pitch) : void{
		if($pitch < BlockNote::MIN_PITCH || $pitch > BlockNote::MAX_PITCH){
			throw new \InvalidArgumentException("Pitch must be in range " . BlockNote::MIN_PITCH . " - " . BlockNote::MAX_PITCH);
		}
		$this->pitch = $pitch;
	}
}
