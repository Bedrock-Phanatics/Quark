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

use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\block\utils\AnimatedContainerLike;
use quark\block\utils\AnimatedContainerLikeTrait;
use quark\block\utils\AnyFacing;
use quark\block\utils\AnyFacingTrait;
use quark\block\utils\Container;
use quark\block\utils\ContainerTrait;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;
use quark\player\Player;
use quark\world\BlockTransaction;
use quark\world\Position;
use quark\world\sound\BarrelCloseSound;
use quark\world\sound\BarrelOpenSound;
use quark\world\sound\Sound;
use function abs;

class Barrel extends Opaque implements AnimatedContainerLike, Container, AnyFacing{
	use AnimatedContainerLikeTrait;
	use ContainerTrait;
	use AnyFacingTrait;

	protected bool $open = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facing($this->facing);
		$w->bool($this->open);
	}

	public function isOpen() : bool{
		return $this->open;
	}

	/** @return $this */
	public function setOpen(bool $open) : Barrel{
		$this->open = $open;
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

	public function getFuelTime() : int{
		return 300;
	}

	protected function getOpenSound() : Sound{
		return new BarrelOpenSound();
	}

	protected function getCloseSound() : Sound{
		return new BarrelCloseSound();
	}

	protected function playAnimationVisual(Position $position, bool $isOpen) : void{
		$world = $position->getWorld();
		$block = $world->getBlock($position);
		if($block instanceof Barrel){
			$world->setBlock($position, $block->setOpen($isOpen));
		}
	}
}
