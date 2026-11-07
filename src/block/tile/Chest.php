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

use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use quark\inventory\Inventory;
use quark\inventory\SimpleInventory;
use quark\world\Position;
use function abs;

class Chest extends Spawnable implements ContainerTile, Nameable{
	use NameableTrait {
		addAdditionalSpawnData as addNameSpawnData;
	}
	use ContainerTileTrait {
		onBlockDestroyedHook as containerTraitBlockDestroyedHook;
	}

	public const TAG_PAIRX = "pairx";
	public const TAG_PAIRZ = "pairz";
	public const TAG_PAIR_LEAD = "pairlead";

	protected Inventory $inventory;

	private ?int $pairX = null;
	private ?int $pairZ = null;

	public function __construct(){
		parent::__construct();
		$this->inventory = new SimpleInventory(27);
	}

	public function readSaveData(CompoundTag $nbt) : void{
		if(($pairXTag = $nbt->getTag(self::TAG_PAIRX)) instanceof IntTag && ($pairZTag = $nbt->getTag(self::TAG_PAIRZ)) instanceof IntTag){
			$this->pairX = $pairXTag->getValue();
			$this->pairZ = $pairZTag->getValue();
			if($this->isAttached()){
				$this->dropDistantPair($this->getPosition());
			}
		}
		$this->loadName($nbt);
		$this->loadItems($nbt);
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		if($this->isPaired()){
			$nbt->setInt(self::TAG_PAIRX, $this->pairX);
			$nbt->setInt(self::TAG_PAIRZ, $this->pairZ);
		}
		$this->saveName($nbt);
		$this->saveItems($nbt);
	}

	public function getCleanedNBT() : ?CompoundTag{
		$tag = parent::getCleanedNBT();
		if($tag !== null){
			//TODO: replace this with a purpose flag on writeSaveData()
			$tag->removeTag(self::TAG_PAIRX, self::TAG_PAIRZ);
		}
		return $tag;
	}

	public function attach(Position $position) : void{
		parent::attach($position);
		$this->dropDistantPair($position);
	}

	/**
	 * Pairs are saved as absolute coordinates, so loaded, moved or item-supplied data can point at a chest that isn't next to this one.
	 */
	private function dropDistantPair(Position $position) : void{
		if($this->pairX !== null && $this->pairZ !== null && abs($position->getFloorX() - $this->pairX) + abs($position->getFloorZ() - $this->pairZ) !== 1){
			$this->pairX = $this->pairZ = null;
		}
	}

	public function close() : void{
		if(!$this->closed){
			$this->inventory->removeAllWindows();
			parent::close();
		}
	}

	protected function onBlockDestroyedHook() : void{
		$this->containerTraitBlockDestroyedHook();
		$this->unpair();
	}

	/**
	 * Returns this half's own contents. Use the Chest block to get the combined contents of a double chest.
	 */
	public function getInventory() : Inventory{
		return $this->inventory;
	}

	public function getDefaultName() : string{
		return "Chest";
	}

	public function isPaired() : bool{
		return $this->pairX !== null && $this->pairZ !== null;
	}

	/**
	 * Returns the other half of the double chest, if it's loaded and agrees that it's paired with this chest.
	 */
	public function getPair() : ?Chest{
		if(!$this->isPaired()){
			return null;
		}
		$pos = $this->getPosition();
		$world = $pos->getWorld();
		if(!$world->isInLoadedTerrain(new Vector3($this->pairX, $pos->y, $this->pairZ))){
			return null;
		}
		$tile = $world->getTileAt($this->pairX, $pos->getFloorY(), $this->pairZ);
		if(!$tile instanceof Chest){
			$this->pairX = $this->pairZ = null;
			$this->clearSpawnCompoundCache();
			return null;
		}
		if(!$tile->isPaired()){
			//repair one-sided pairings left behind by old worlds
			$tile->createPair($this);
			$tile->clearSpawnCompoundCache();
		}elseif($tile->pairX !== $pos->getFloorX() || $tile->pairZ !== $pos->getFloorZ()){
			//two chests claiming the same partner would otherwise both expose its contents as part of themselves
			return null;
		}
		return $tile;
	}

	public function pairWith(Chest $tile) : bool{
		if($tile === $this || $this->isPaired() || $tile->isPaired()){
			return false;
		}

		$this->createPair($tile);

		$this->clearSpawnCompoundCache();
		$tile->clearSpawnCompoundCache();

		return true;
	}

	private function createPair(Chest $tile) : void{
		$this->pairX = $tile->getPosition()->getFloorX();
		$this->pairZ = $tile->getPosition()->getFloorZ();

		$tile->pairX = $this->getPosition()->getFloorX();
		$tile->pairZ = $this->getPosition()->getFloorZ();
	}

	public function unpair() : bool{
		if(!$this->isPaired()){
			return false;
		}

		$tile = $this->getPair();
		$this->pairX = $this->pairZ = null;

		$this->clearSpawnCompoundCache();

		if($tile instanceof Chest){
			$tile->pairX = $tile->pairZ = null;
			$tile->clearSpawnCompoundCache();
		}

		return true;
	}

	protected function addAdditionalSpawnData(CompoundTag $nbt) : void{
		if($this->isPaired()){
			$nbt->setInt(self::TAG_PAIRX, $this->pairX);
			$nbt->setInt(self::TAG_PAIRZ, $this->pairZ);
		}

		$this->addNameSpawnData($nbt);
	}
}
