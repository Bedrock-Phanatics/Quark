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

use quark\block\utils\DripstoneThickness;
use quark\block\utils\SupportType;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;
use quark\math\AxisAlignedBB;
use quark\math\Facing;
use quark\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;

/**
 * Thickness is derived from neighbouring dripstone and kept up to date on placement and neighbour updates.
 * TODO: Implement dripping, filling cauldrons, growth, fall damage and stalactites falling when unsupported.
 */
class PointedDripstone extends Transparent{
	protected DripstoneThickness $thickness = DripstoneThickness::TIP;
	protected bool $hanging = true;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->enum($this->thickness);
		$w->bool($this->hanging);
	}

	public function getThickness() : DripstoneThickness{ return $this->thickness; }

	/** @return $this */
	public function setThickness(DripstoneThickness $thickness) : self{
		$this->thickness = $thickness;
		return $this;
	}

	public function isHanging() : bool{ return $this->hanging; }

	/** @return $this */
	public function setHanging(bool $hanging) : self{
		$this->hanging = $hanging;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->contract(5 / 16, 0, 5 / 16)];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	private function getTipDirection() : int{
		return $this->hanging ? Facing::DOWN : Facing::UP;
	}

	private function canBeSupportedAt(Block $block) : bool{
		$baseFace = Facing::opposite($this->getTipDirection());
		$support = $block->getSide($baseFace);
		return ($support instanceof PointedDripstone && $support->hanging === $this->hanging) ||
			$support->getSupportType(Facing::opposite($baseFace)) === SupportType::FULL;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->hanging = $face === Facing::DOWN;
		if(!$this->canBeSupportedAt($blockReplace)){
			$this->hanging = !$this->hanging;
			if(!$this->canBeSupportedAt($blockReplace)){
				return false;
			}
		}
		$this->thickness = $this->computeThickness($blockReplace);
		return parent::place($tx, $item, $blockReplace, $face, $clickVector, $player);
	}

	public function onNearbyBlockChange() : void{
		if(!$this->canBeSupportedAt($this)){
			$this->position->getWorld()->useBreakOn($this->position);
			return;
		}
		$thickness = $this->computeThickness($this);
		if($thickness !== $this->thickness){
			$this->thickness = $thickness;
			$this->position->getWorld()->setBlock($this->position, $this);
		}
	}

	private function computeThickness(Block $at) : DripstoneThickness{
		$tipDirection = $this->getTipDirection();
		$next = $at->getSide($tipDirection);
		if(!$next instanceof PointedDripstone){
			return DripstoneThickness::TIP;
		}
		if($next->hanging !== $this->hanging){
			return DripstoneThickness::TIP_MERGE;
		}
		$afterNext = $next->getSide($tipDirection);
		if(!$afterNext instanceof PointedDripstone || $afterNext->hanging !== $this->hanging){
			return DripstoneThickness::FRUSTUM;
		}
		$previous = $at->getSide(Facing::opposite($tipDirection));
		return $previous instanceof PointedDripstone && $previous->hanging === $this->hanging ?
			DripstoneThickness::MIDDLE :
			DripstoneThickness::BASE;
	}
}
