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

use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\network\mcpe\protocol\BlockEventPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use quark\block\inventory\window\BlockInventoryWindow;
use quark\block\inventory\window\DoubleChestInventoryWindow;
use quark\block\tile\Chest as TileChest;
use quark\block\utils\AnimatedContainerLike;
use quark\block\utils\AnimatedContainerLikeTrait;
use quark\block\utils\Container;
use quark\block\utils\ContainerTrait;
use quark\block\utils\FacesOppositePlacingPlayerTrait;
use quark\block\utils\HorizontalFacing;
use quark\block\utils\SupportType;
use quark\event\block\ChestPairEvent;
use quark\inventory\CombinedInventoryProxy;
use quark\inventory\Inventory;
use quark\player\Player;
use quark\world\Position;
use quark\world\sound\ChestCloseSound;
use quark\world\sound\ChestOpenSound;
use quark\world\sound\Sound;

class Chest extends Transparent implements AnimatedContainerLike, Container, HorizontalFacing{
	use AnimatedContainerLikeTrait;
	use ContainerTrait;
	use FacesOppositePlacingPlayerTrait;

	protected function recalculateCollisionBoxes() : array{
		//these are slightly bigger than in PC
		return [AxisAlignedBB::one()->contract(0.025, 0, 0.025)->trim(Facing::UP, 0.05)];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function onPostPlace() : void{
		$world = $this->position->getWorld();
		$tile = $world->getTile($this->position);
		if($tile instanceof TileChest){
			foreach([false, true] as $clockwise){
				$side = Facing::rotateY($this->facing, $clockwise);
				$c = $this->getSide($side);
				if($c instanceof Chest && $c->hasSameTypeId($this) && $c->facing === $this->facing){
					$pair = $world->getTile($c->position);
					if($pair instanceof TileChest && !$pair->isPaired()){
						[$left, $right] = $clockwise ? [$c, $this] : [$this, $c];
						$ev = new ChestPairEvent($left, $right);
						$ev->call();
						if(!$ev->isCancelled() && $world->getBlock($this->position)->hasSameTypeId($this) && $world->getBlock($c->position)->hasSameTypeId($c)){
							$pair->pairWith($tile);
							$tile->pairWith($pair);
							break;
						}
					}
				}
			}
		}
	}

	public function isOpeningObstructed() : bool{
		if(!$this->getSide(Facing::UP)->isTransparent()){
			return true;
		}
		$pair = $this->getTile()?->getPair();
		return $pair !== null && !$pair->getBlock()->getSide(Facing::UP)->isTransparent();
	}

	private function getTile() : ?TileChest{
		$tile = $this->position->getWorld()->getTile($this->position);
		return $tile instanceof TileChest ? $tile : null;
	}

	/**
	 * Returns both halves of a double chest in the order the client lays them out, or just this half if unpaired.
	 *
	 * @phpstan-return array{TileChest, ?TileChest}|null
	 */
	private function getHalves() : ?array{
		$tile = $this->getTile();
		if($tile === null){
			return null;
		}
		$pair = $tile->getPair();
		if($pair === null){
			return [$tile, null];
		}
		$pairPos = $pair->getPosition();
		return ($pairPos->x + ($pairPos->z << 15)) > ($this->position->x + ($this->position->z << 15)) ? [$pair, $tile] : [$tile, $pair];
	}

	/**
	 * Returns the chest's contents, combined with the other half's if this is a double chest. A new combined view is
	 * created on every call, so don't rely on its identity.
	 */
	public function getInventory() : ?Inventory{
		$halves = $this->getHalves();
		if($halves === null){
			return null;
		}
		[$left, $right] = $halves;
		return $right === null ? $left->getInventory() : new CombinedInventoryProxy([$left->getInventory(), $right->getInventory()]);
	}

	public function openToUnchecked(Player $player) : bool{
		$halves = $this->getHalves();
		if($halves === null){
			return false;
		}
		[$left, $right] = $halves;
		$window = $right === null ?
			new BlockInventoryWindow($player, $left->getInventory(), $this->position) :
			new DoubleChestInventoryWindow($player, new CombinedInventoryProxy([$left->getInventory(), $right->getInventory()]), $left->getPosition(), $right->getPosition());
		return $player->setCurrentWindow($window);
	}

	protected function getOpenSound() : Sound{
		return new ChestOpenSound();
	}

	protected function getCloseSound() : Sound{
		return new ChestCloseSound();
	}

	protected function playAnimationVisual(Position $position, bool $isOpen) : void{
		//event ID is always 1 for a chest
		$position->getWorld()->broadcastPacketToViewers($position, BlockEventPacket::create(BlockPosition::fromVector3($position), 1, $isOpen ? 1 : 0));
	}

	protected function doAnimationEffects(bool $isOpen) : void{
		foreach($this->getHalves() ?? [] as $half){
			if($half !== null){
				$this->playAnimationVisual($half->getPosition(), $isOpen);
				$this->playAnimationSound($half->getPosition(), $isOpen);
			}
		}
	}

	public function getFuelTime() : int{
		return 300;
	}
}
