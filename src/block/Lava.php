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

use quark\entity\Entity;
use quark\event\entity\EntityCombustByBlockEvent;
use quark\event\entity\EntityDamageByBlockEvent;
use quark\event\entity\EntityDamageEvent;
use pocketmine\math\Facing;
use quark\world\sound\BucketEmptyLavaSound;
use quark\world\sound\BucketFillLavaSound;
use quark\world\sound\Sound;

class Lava extends Liquid{

	public function getLightLevel() : int{
		return 15;
	}

	public function getBucketFillSound() : Sound{
		return new BucketFillLavaSound();
	}

	public function getBucketEmptySound() : Sound{
		return new BucketEmptyLavaSound();
	}

	public function tickRate() : int{
		return 30;
	}

	public function getFlowDecayPerBlock() : int{
		return 2; //TODO: this is 1 in the nether
	}

	/**
	 * @phpstan-return \Generator<int, Block, void, void>
	 */
	private function getAdjacentBlocksExceptDown() : \Generator{
		foreach(Facing::ALL as $side){
			if($side === Facing::DOWN){
				continue;
			}
			yield $this->getSide($side);
		}
	}

	protected function checkForHarden() : bool{
		if($this->falling){
			return false;
		}
		foreach($this->getAdjacentBlocksExceptDown() as $colliding){
			if($colliding instanceof Water){
				if($this->decay === 0){
					$this->liquidCollide($colliding, VanillaBlocks::OBSIDIAN());
					return true;
				}elseif($this->decay <= 4){
					$this->liquidCollide($colliding, VanillaBlocks::COBBLESTONE());
					return true;
				}
			}
		}

		if($this->getSide(Facing::DOWN)->getTypeId() === BlockTypeIds::SOUL_SOIL){
			foreach($this->getAdjacentBlocksExceptDown() as $colliding){
				if($colliding->getTypeId() === BlockTypeIds::BLUE_ICE){
					$this->liquidCollide($colliding, VanillaBlocks::BASALT());
					return true;
				}
			}
		}

		return false;
	}

	protected function flowIntoBlock(Block $block, int $newFlowDecay, bool $falling) : void{
		if($block instanceof Water){
			$block->liquidCollide($this, VanillaBlocks::STONE());
		}else{
			parent::flowIntoBlock($block, $newFlowDecay, $falling);
		}
	}

	public function onEntityInside(Entity $entity) : bool{
		$ev = new EntityDamageByBlockEvent($this, $entity, EntityDamageEvent::CAUSE_LAVA, 4);
		$entity->attack($ev);

		//in java burns entities for 15 seconds - seems to be a parity issue in bedrock
		$ev = new EntityCombustByBlockEvent($this, $entity, 8);
		$ev->call();
		if(!$ev->isCancelled()){
			$entity->setOnFire($ev->getDuration());
		}

		$entity->resetFallDistance();
		return true;
	}
}
