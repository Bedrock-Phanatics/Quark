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

namespace quark\item;

use quark\data\runtime\RuntimeDataDescriber;
use quark\entity\Living;
use quark\player\Player;
use quark\world\sound\BottleEmptySound;

class Potion extends Item implements ConsumableItem{

	private PotionType $potionType = PotionType::WATER;

	protected function describeState(RuntimeDataDescriber $w) : void{
		$w->enum($this->potionType);
	}

	public function getType() : PotionType{ return $this->potionType; }

	/**
	 * @return $this
	 */
	public function setType(PotionType $type) : self{
		$this->potionType = $type;
		return $this;
	}

	public function getMaxStackSize() : int{
		return 1;
	}

	public function onConsume(Living $consumer) : void{
		$consumer->broadcastSound(new BottleEmptySound());
	}

	public function getAdditionalEffects() : array{
		//TODO: check CustomPotionEffects NBT
		return $this->potionType->getEffects();
	}

	public function getResidue() : Item{
		return VanillaItems::GLASS_BOTTLE();
	}

	public function canStartUsingItem(Player $player) : bool{
		return true;
	}
}
