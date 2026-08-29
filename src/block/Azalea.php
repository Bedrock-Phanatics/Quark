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
use quark\event\block\StructureGrowEvent;
use quark\item\Fertilizer;
use quark\item\Item;
use pocketmine\math\Axis;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\utils\Random;
use quark\world\generator\object\TreeFactory;
use quark\world\generator\object\TreeType;
use function mt_rand;

class Azalea extends Flowable{
	use StaticSupportTrait;

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($item instanceof Fertilizer){
			$item->pop();
			if($player === null || !$player->hasFiniteResources() || mt_rand(1, 100) <= 45){
				$this->grow($player);
			}
			return true;
		}

		return false;
	}

	private function grow(?Player $player) : void{
		$random = new Random(mt_rand());
		$tree = TreeFactory::get($random, TreeType::AZALEA);
		$transaction = $tree?->getBlockTransaction($this->position->getWorld(), $this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ(), $random);
		if($transaction === null){
			return;
		}

		$ev = new StructureGrowEvent($this, $transaction, $player);
		$ev->call();
		if(!$ev->isCancelled()){
			$transaction->apply();
		}
	}

	protected function recalculateCollisionBoxes() : array{
		return [
			AxisAlignedBB::one()
				->squash(Axis::X, 6 / 16)
				->squash(Axis::Z, 6 / 16)
				->trim(Facing::UP, 8 / 16),
			AxisAlignedBB::one()->trim(Facing::DOWN, 8 / 16)
		];
	}

	private function canBeSupportedAt(Block $block) : bool{
		//TODO: Moss block
		$supportBlock = $block->getSide(Facing::DOWN);
		return $supportBlock->getTypeId() === BlockTypeIds::CLAY ||
			$supportBlock->hasTypeTag(BlockTypeTags::DIRT) ||
			$supportBlock->hasTypeTag(BlockTypeTags::MUD);
	}
}
