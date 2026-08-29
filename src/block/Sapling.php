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

use quark\block\utils\SaplingType;
use quark\block\utils\StaticSupportTrait;
use quark\data\runtime\RuntimeDataDescriber;
use quark\event\block\StructureGrowEvent;
use quark\item\Fertilizer;
use quark\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\utils\Random;
use quark\world\generator\object\TreeFactory;
use function mt_rand;

class Sapling extends Flowable{
	use StaticSupportTrait;

	protected bool $ready = false;

	private SaplingType $saplingType;

	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo, SaplingType $saplingType){
		parent::__construct($idInfo, $name, $typeInfo);
		$this->saplingType = $saplingType;
	}

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->ready);
	}

	public function isReady() : bool{ return $this->ready; }

	/** @return $this */
	public function setReady(bool $ready) : self{
		$this->ready = $ready;
		return $this;
	}

	private function canBeSupportedAt(Block $block) : bool{
		$supportBlock = $block->getSide(Facing::DOWN);
		return $supportBlock->hasTypeTag(BlockTypeTags::DIRT) || $supportBlock->hasTypeTag(BlockTypeTags::MUD);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($item instanceof Fertilizer && $this->grow($player)){
			$item->pop();

			return true;
		}

		return false;
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		$world = $this->position->getWorld();
		if($world->getFullLightAt($this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ()) >= 8 && mt_rand(1, 7) === 1){
			if($this->ready){
				$this->grow(null);
			}else{
				$this->ready = true;
				$world->setBlock($this->position, $this);
			}
		}
	}

	private function grow(?Player $player) : bool{
		$random = new Random(mt_rand());
		$tree = TreeFactory::get($random, $this->saplingType->getTreeType());
		$transaction = $tree?->getBlockTransaction($this->position->getWorld(), $this->position->getFloorX(), $this->position->getFloorY(), $this->position->getFloorZ(), $random);
		if($transaction === null){
			return false;
		}

		$ev = new StructureGrowEvent($this, $transaction, $player);
		$ev->call();
		if(!$ev->isCancelled()){
			return $transaction->apply();
		}
		return false;
	}

	public function getFuelTime() : int{
		return 100;
	}
}
