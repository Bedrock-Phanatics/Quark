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

use quark\block\tile\dispenser\Dispenser as DispenserTile;

use quark\block\utils\AnyFacing;
use quark\block\utils\PoweredByRedstone;
use quark\block\utils\PoweredByRedstoneTrait;
use quark\block\utils\redstone\Powerable;
use quark\block\utils\redstone\PowerableTrait;
use quark\block\utils\redstone\RedstoneBlockAccessTrait;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\timings\Timings;
use quark\timings\TimingsHandler;
use quark\world\BlockTransaction;
use function abs;

class Dispenser extends Opaque implements AnyFacing, Powerable, PoweredByRedstone{
	use RedstoneBlockAccessTrait;
	use PowerableTrait;
	use PoweredByRedstoneTrait;

	protected int $facing = Facing::DOWN;
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

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
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

		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player instanceof Player){
			$tile = $this->position->getWorld()->getTileAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
			if($tile instanceof DispenserTile){
				$player->setCurrentWindow($tile->getInventory());
			}
		}
		return true;
	}

	public function acceptsPowerFromSide(int $side) : bool{
		return $side !== $this->facing;
	}

	public function getFacing() : int{
		return $this->facing;
	}

	protected function onReceivePower(int $power) : void{
		if(!TimingsHandler::isEnabled()){
			$this->handleReceivedPower($power);
			return;
		}
		Timings::$redstoneDispensers->time(fn() => $this->handleReceivedPower($power));
	}

	private function handleReceivedPower(int $power) : void{
		$powered = $power > 0;
		if($powered !== $this->powered){
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
}
