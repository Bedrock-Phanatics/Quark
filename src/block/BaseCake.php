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
use quark\entity\effect\EffectInstance;
use quark\entity\FoodSource;
use quark\entity\Living;
use quark\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;

abstract class BaseCake extends Transparent implements FoodSource{
	use StaticSupportTrait;

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	private function canBeSupportedAt(Block $block) : bool{
		return $block->getSide(Facing::DOWN)->getTypeId() !== BlockTypeIds::AIR;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player !== null){
			return $player->consumeObject($this);
		}

		return false;
	}

	public function getFoodRestore() : int{
		return 2;
	}

	public function getSaturationRestore() : float{
		return 0.4;
	}

	public function requiresHunger() : bool{
		return true;
	}

	/**
	 * @return EffectInstance[]
	 */
	public function getAdditionalEffects() : array{
		return [];
	}

	abstract public function getResidue() : Block;

	public function onConsume(Living $consumer) : void{
		$this->position->getWorld()->setBlock($this->position, $this->getResidue());
	}
}
