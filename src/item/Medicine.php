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

class Medicine extends Item implements ConsumableItem{

	private MedicineType $medicineType = MedicineType::EYE_DROPS;

	protected function describeState(RuntimeDataDescriber $w) : void{
		$w->enum($this->medicineType);
	}

	public function getType() : MedicineType{ return $this->medicineType; }

	/**
	 * @return $this
	 */
	public function setType(MedicineType $type) : self{
		$this->medicineType = $type;
		return $this;
	}

	public function getMaxStackSize() : int{
		return 1;
	}

	public function onConsume(Living $consumer) : void{
		$consumer->getEffects()->remove($this->getType()->getCuredEffect());
	}

	public function getAdditionalEffects() : array{
		return [];
	}

	public function getResidue() : Item{
		return VanillaItems::GLASS_BOTTLE();
	}

	public function canStartUsingItem(Player $player) : bool{
		return $player->getEffects()->has($this->getType()->getCuredEffect());
	}
}
