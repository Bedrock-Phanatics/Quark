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

use quark\block\utils\StaticSupportTrait;
use quark\block\utils\SupportType;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;
use quark\math\Facing;
use quark\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;

/**
 * TODO: Implement bone meal growth.
 */
class PaleHangingMoss extends Flowable{
	use StaticSupportTrait {
		onNearbyBlockChange as onSupportChange;
	}

	protected bool $tip = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->tip);
	}

	public function isTip() : bool{ return $this->tip; }

	/** @return $this */
	public function setTip(bool $tip) : self{
		$this->tip = $tip;
		return $this;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		$this->tip = !$blockReplace->getSide(Facing::DOWN) instanceof PaleHangingMoss;
		return parent::place($tx, $item, $blockReplace, $face, $clickVector, $player);
	}

	public function onNearbyBlockChange() : void{
		$this->onSupportChange();
		$world = $this->position->getWorld();
		if($world->getBlock($this->position) instanceof PaleHangingMoss){
			$tip = !$this->getSide(Facing::DOWN) instanceof PaleHangingMoss;
			if($tip !== $this->tip){
				$world->setBlock($this->position, $this->setTip($tip));
			}
		}
	}

	private function canBeSupportedAt(Block $block) : bool{
		$up = $block->getSide(Facing::UP);
		return $up instanceof PaleHangingMoss || $up->getSupportType(Facing::DOWN) === SupportType::FULL;
	}

	public function getFlameEncouragement() : int{
		return 15;
	}

	public function getFlammability() : int{
		return 100;
	}
}
