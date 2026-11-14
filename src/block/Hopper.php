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

use quark\block\inventory\window\HopperInventoryWindow;
use quark\block\tile\Hopper as TileHopper;
use quark\block\utils\Container;
use quark\block\utils\ContainerTrait;
use quark\block\utils\PoweredByRedstone;
use quark\block\utils\PoweredByRedstoneTrait;
use quark\block\utils\redstone\Powerable;
use quark\block\utils\redstone\PowerableTrait;
use quark\block\utils\redstone\RedstoneBlockAccessTrait;
use quark\block\utils\SupportType;
use quark\data\runtime\RuntimeDataDescriber;
use quark\entity\Entity;
use quark\entity\object\ItemEntity;
use quark\inventory\Inventory;
use quark\item\Item;
use quark\math\AxisAlignedBB;
use quark\math\Facing;
use quark\math\Vector3;
use quark\player\InventoryWindow;
use quark\player\Player;
use quark\world\BlockTransaction;
use quark\world\Position;

class Hopper extends Transparent implements Container, PoweredByRedstone, Powerable{
	use ContainerTrait;
	use PoweredByRedstoneTrait;
	use RedstoneBlockAccessTrait;
	use PowerableTrait {
		onNearbyBlockChange as private powerableNearbyBlockChange;
	}

	private(set) int $activation_delay = 0;
	private(set) int $deactivation_delay = 0;
	private(set) bool $requires_strong_power = false;

	private int $facing = Facing::DOWN;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facingExcept($this->facing, Facing::UP);
		$w->bool($this->powered);
	}

	public function getFacing() : int{ return $this->facing; }

	/** @return $this */
	public function setFacing(int $facing) : self{
		if($facing === Facing::UP){
			throw new \InvalidArgumentException("Hopper may not face upward");
		}
		$this->facing = $facing;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		$result = [
			AxisAlignedBB::one()->trim(Facing::UP, 6 / 16) //the empty area around the bottom is currently considered solid
		];

		foreach(Facing::HORIZONTAL as $f){ //add the frame parts around the bowl
			$result[] = AxisAlignedBB::one()->trim($f, 14 / 16);
		}
		return $result;
	}

	public function getSupportType(int $facing) : SupportType{
		return match($facing){
			Facing::UP => SupportType::FULL,
			Facing::DOWN => $this->facing === Facing::DOWN ? SupportType::CENTER : SupportType::NONE,
			default => SupportType::NONE
		};
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->facing = $face === Facing::DOWN ? Facing::DOWN : Facing::opposite($face);

		return parent::place($tx, $item, $blockReplace, $face, $clickVector, $player);
	}

	private function getHopperTile() : ?TileHopper{
		$tile = $this->position->getWorld()->getTileAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
		return $tile instanceof TileHopper ? $tile : null;
	}

	public function onScheduledUpdate() : void{
		$this->getHopperTile()?->onBlockUpdate($this);
	}

	public function onNearbyBlockChange() : void{
		$this->powerableNearbyBlockChange();
		$tile = $this->getHopperTile();
		if($tile !== null){
			$tile->setLocked($this->powered);
			$tile->wake();
		}
	}

	public function hasEntityCollision() : bool{
		return true;
	}

	//item entities also report the hopper below them, since it collects from the block space above too
	public function onEntityInside(Entity $entity) : bool{
		if(!$entity instanceof ItemEntity){
			return true;
		}
		$tile = $this->getHopperTile();
		if($tile === null){
			return false;
		}
		$tile->onItemNearby($entity);
		return true;
	}

	//a powered hopper is locked, which the client shows through the toggle bit
	protected function onReceivePower(int $power) : void{
		$powered = $power > 0;
		if($powered !== $this->powered){
			$this->position->getWorld()->setBlockAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z, $this->setPowered($powered), false);
			$this->getHopperTile()?->setLocked($powered);
		}
	}

	protected function newMenu(Player $player, Inventory $inventory, Position $position) : InventoryWindow{
		return new HopperInventoryWindow($player, $inventory, $position);
	}
}
