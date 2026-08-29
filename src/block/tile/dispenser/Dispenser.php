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

namespace quark\block\tile\dispenser;

use quark\block\tile\Container;
use quark\block\tile\ContainerTrait;
use quark\block\tile\Nameable;
use quark\block\tile\NameableTrait;
use quark\block\tile\Spawnable;
use quark\inventory\DispenserInventory;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\LevelEventPacket;
use pocketmine\network\mcpe\protocol\types\LevelEvent;
use quark\player\Player;
use quark\world\redstone\RedstoneConfig;
use quark\world\redstone\RedstoneManager;
use quark\world\World;
use function array_rand;
use function count;

class Dispenser extends Spawnable implements Container, Nameable{
	use ContainerTrait;
	use NameableTrait {
		addAdditionalSpawnData as addNameSpawnData;
	}

	private DispenserInventory $inventory;

	public function __construct(World $world, Vector3 $pos){
		parent::__construct($world, $pos);
		$this->inventory = new DispenserInventory($this->position);
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->loadName($nbt);
		$this->loadItems($nbt);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->saveName($nbt);
		$this->saveItems($nbt);
	}

	public function close() : void{
		if(!$this->closed){
			$this->inventory->removeAllViewers();
			parent::close();
		}
	}

	public function getInventory() : DispenserInventory{
		return $this->inventory;
	}

	public function getRealInventory() : DispenserInventory{
		return $this->inventory;
	}

	public function getDefaultName() : string{
		return "Dispenser";
	}

	public function onPower(int $facing, ?Player $player = null) : void{
		$manager = RedstoneManager::getInstance();
		if(!$manager->isEnabledAt($this->position) || !RedstoneConfig::areDispensersEnabled() || !$manager->get($this->position->getWorld())->tryDispenserAction()){ return; }
		static $faces = [
			0 => 40,
			1 => 13,
			2 => 19,
			3 => 7,
			4 => 21,
			5 => 5,
		];

		/** @var \quark\block\Dispenser $dispenser */
		$dispenser = $this->getBlock();
		$dispenser_pos = $dispenser->getPosition();

		$side_block = $dispenser_pos->getSide($facing);
		$world = $this->position->getWorld();
		$sideX = (int) $side_block->x;
		$sideY = (int) $side_block->y;
		$sideZ = (int) $side_block->z;
		if(!$world->isInWorld($sideX, $sideY, $sideZ) || !$world->isChunkLoaded($sideX >> 4, $sideZ >> 4)){ return; }

		$pos = $side_block->add(0.5, $facing === 0 ? -1 : ($facing === 1 ? 1 : 0.5), 0.5);

		$contents = \quark\block\utils\redstone\RedstoneBlockUtils::fastReadOnlyInventoryContents($this->inventory);
		if(count($contents) === 0){
			$this->position->getWorld()->broadcastPacketToViewers($this->position, LevelEventPacket::create(LevelEvent::SOUND_CLICK_FAIL, 0, $this->position));
		}else{
			/** @var int $slot */
			$slot = array_rand($contents);
			if(!DispensableItemManager::get($this->inventory->getItem($slot))->dispense($this->position, $this->inventory, $slot, $side_block, $facing, $player)){
				$this->position->getWorld()->broadcastPacketToViewers($this->position, LevelEventPacket::create(LevelEvent::SOUND_CLICK_FAIL, 0, $this->position));
			}else{
				$this->position->getWorld()->broadcastPacketToViewers($this->position, LevelEventPacket::create(LevelEvent::SOUND_CLICK, 0, $this->position));
				$this->position->getWorld()->broadcastPacketToViewers($pos, LevelEventPacket::create(LevelEvent::PARTICLE_SHOOT, $faces[$dispenser->getFacing()], $pos));
			}
		}
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		$this->addNameSpawnData($nbt);
	}
}
