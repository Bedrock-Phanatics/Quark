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

use quark\block\utils\HorizontalFacing;
use quark\block\utils\HorizontalFacingTrait;
use quark\block\utils\SupportType;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;
use quark\world\sound\DoorSound;

class Trapdoor extends Transparent implements HorizontalFacing{
	use HorizontalFacingTrait;

	protected bool $open = false;
	protected bool $top = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->bool($this->top);
		$w->bool($this->open);
	}

	public function isOpen() : bool{ return $this->open; }

	/** @return $this */
	public function setOpen(bool $open) : self{
		$this->open = $open;
		return $this;
	}

	public function isTop() : bool{ return $this->top; }

	/** @return $this */
	public function setTop(bool $top) : self{
		$this->top = $top;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		//TODO: like doors, these are slightly too thin in Bugrock (0.1825 instead of 0.1875)
		return [AxisAlignedBB::one()->trim($this->open ? $this->facing : ($this->top ? Facing::DOWN : Facing::UP), 1 - 0.1825)];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			$this->facing = Facing::opposite($player->getHorizontalFacing());
		}
		if(($clickVector->y > 0.5 && $face !== Facing::UP) || $face === Facing::DOWN){
			$this->top = true;
		}

		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$this->open = !$this->open;
		$world = $this->position->getWorld();
		$world->setBlock($this->position, $this);
		$world->addSound($this->position, new DoorSound());
		return true;
	}
}
