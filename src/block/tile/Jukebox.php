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

use quark\item\Item;
use quark\item\Record;
use pocketmine\nbt\tag\CompoundTag;
use quark\network\mcpe\convert\TypeConverter;
use quark\world\sound\RecordStopSound;

class Jukebox extends Spawnable{
	private const TAG_RECORD = "RecordItem"; //Item CompoundTag

	private ?Record $record = null;

	public function getRecord() : ?Record{
		return $this->record;
	}

	public function setRecord(?Record $record) : void{
		$this->record = $record;
	}

	public function readSaveData(CompoundTag $nbt) : void{
		if(($tag = $nbt->getCompoundTag(self::TAG_RECORD)) !== null){
			$record = Item::safeNbtDeserialize($tag, "Jukebox ($this->position) record");
			if($record instanceof Record){
				$this->record = $record;
			}
		}
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		if($this->record !== null){
			$nbt->setTag(self::TAG_RECORD, $this->record->nbtSerialize());
		}
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		//this is needed for the note particles to show on the client side
		if($this->record !== null){
			$nbt->setTag(self::TAG_RECORD, TypeConverter::getInstance()->getItemTranslator()->toNetworkNbt($this->record));
		}
	}

	protected function onBlockDestroyedHook() : void{
		$this->position->getWorld()->addSound($this->position, new RecordStopSound());
	}
}
