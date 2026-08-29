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

use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;

class DoublePlant extends Flowable{
	protected bool $top = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->top);
	}

	public function isTop() : bool{ return $this->top; }

	/** @return $this */
	public function setTop(bool $top) : self{
		$this->top = $top;
		return $this;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$down = $blockReplace->getSide(Facing::DOWN);
		if($down->hasTypeTag(BlockTypeTags::DIRT) && $blockReplace->getSide(Facing::UP)->canBeReplaced()){
			$top = clone $this;
			$top->top = true;
			$tx->addBlock($blockReplace->position, $this)->addBlock($blockReplace->position->getSide(Facing::UP), $top);
			return true;
		}

		return false;
	}

	/**
	 * Returns whether this double-plant has a corresponding other half.
	 */
	public function isValidHalfPlant() : bool{
		$other = $this->getSide($this->top ? Facing::DOWN : Facing::UP);

		return (
			$other instanceof DoublePlant &&
			$other->hasSameTypeId($this) &&
			$other->top !== $this->top
		);
	}

	public function onNearbyBlockChange() : void{
		$down = $this->getSide(Facing::DOWN);
		if(!$this->isValidHalfPlant() || (!$this->top && !$down->hasTypeTag(BlockTypeTags::DIRT) && !$down->hasTypeTag(BlockTypeTags::MUD))){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}

	public function getDrops(Item $item) : array{
		return $this->top ? parent::getDrops($item) : [];
	}

	public function getAffectedBlocks() : array{
		if($this->isValidHalfPlant()){
			return [$this, $this->getSide($this->top ? Facing::DOWN : Facing::UP)];
		}

		return parent::getAffectedBlocks();
	}

	public function getFlameEncouragement() : int{
		return 60;
	}

	public function getFlammability() : int{
		return 100;
	}
}
