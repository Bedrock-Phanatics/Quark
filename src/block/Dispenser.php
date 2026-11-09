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

use quark\block\inventory\window\DispenserInventoryWindow;

use quark\block\tile\dispenser\Dispenser as DispenserTile;
use quark\block\utils\AnyFacing;
use quark\block\utils\Container;
use quark\block\utils\ContainerTrait;
use quark\block\utils\PoweredByRedstone;
use quark\block\utils\PoweredByRedstoneTrait;
use quark\block\utils\redstone\Powerable;
use quark\block\utils\redstone\PowerableTrait;
use quark\block\utils\redstone\RedstoneBlockAccessTrait;
use quark\data\runtime\RuntimeDataDescriber;
use quark\inventory\Inventory;
use quark\item\Item;
use quark\math\Facing;
use quark\math\Vector3;
use quark\player\InventoryWindow;
use quark\player\Player;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;
use quark\world\BlockTransaction;
use quark\world\Position;
use quark\world\redstone\RedstoneConfig;
use quark\world\redstone\RedstoneManager;
use function abs;

class Dispenser extends Opaque implements Container, AnyFacing, Powerable, PoweredByRedstone{
	use ContainerTrait;
	use RedstoneBlockAccessTrait;
	use PowerableTrait;
	use PoweredByRedstoneTrait;

	protected int $facing = Facing::SOUTH;
	private(set) int $activation_delay = 0;
	private(set) int $deactivation_delay = 1;
	private(set) bool $requires_strong_power = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facing($this->facing);
		$w->bool($this->powered);
	}

	public function setFacing(int $facing) : self{
		$this->facing = $facing;
		return $this;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			if(abs($player->getPosition()->x - $this->position->x) < 2 && abs($player->getPosition()->z - $this->position->z) < 2){
				$y = $player->getEyePos()->y;

				if($y - $this->position->y > 2){
					$this->facing = Facing::UP;
				}elseif($this->position->y - $y > 0){
					$this->facing = Facing::DOWN;
				}else{
					$this->facing = Facing::opposite($player->getHorizontalFacing());
				}
			}else{
				$this->facing = Facing::opposite($player->getHorizontalFacing());
			}
		}

		return parent::place($tx, $item, $blockReplace, $face, $clickVector, $player);
	}

	public function acceptsPowerFromSide(int $side) : bool{
		return $side !== $this->facing;
	}

	public function getFacing() : int{
		return $this->facing;
	}

	protected function onReceivePower(int $power) : void{
		if(!Pulse::isRecording()){
			$this->handleReceivedPower($power);
			return;
		}
		PulseZones::$redstoneDispensers->time(fn() => $this->handleReceivedPower($power));
	}

	private function handleReceivedPower(int $power) : void{
		$powered = $power > 0;
		if($powered !== $this->powered){
			if($powered && RedstoneConfig::areDispensersEnabled()){
				$world = RedstoneManager::getInstance()->get($this->position->getWorld());
				if(!$world->tryDispenserAction()){
					//staying unpowered makes the retry see a rising edge again
					$world->scheduleWaitableUpdate($this, 1);
					return;
				}
			}
			$this->powered = $powered;
			$this->position->getWorld()->setBlockAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z, $this, false);
			if($powered){
				$tile = $this->position->getWorld()->getTileAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
				if($tile instanceof DispenserTile){
					$tile->onPower($this->facing);
				}
			}
		}
	}

	protected function newMenu(Player $player, Inventory $inventory, Position $position) : InventoryWindow{
		return new DispenserInventoryWindow($player, $inventory, $position);
	}
}
