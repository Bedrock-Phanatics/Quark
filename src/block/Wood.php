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

use quark\block\utils\PillarRotation;
use quark\block\utils\PillarRotationTrait;
use quark\block\utils\WoodMaterial;
use quark\block\utils\WoodTypeTrait;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Axe;
use quark\item\Item;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\sound\ItemUseOnBlockSound;

class Wood extends Opaque implements PillarRotation, WoodMaterial{
	use PillarRotationTrait;
	use WoodTypeTrait;

	private bool $stripped = false;

	public function describeBlockItemState(RuntimeDataDescriber $w) : void{
		$w->bool($this->stripped);
	}

	public function isStripped() : bool{ return $this->stripped; }

	/** @return $this */
	public function setStripped(bool $stripped) : self{
		$this->stripped = $stripped;
		return $this;
	}

	public function getFuelTime() : int{
		return $this->woodType->isFlammable() ? 300 : 0;
	}

	public function getFlameEncouragement() : int{
		return $this->woodType->isFlammable() ? 5 : 0;
	}

	public function getFlammability() : int{
		return $this->woodType->isFlammable() ? 5 : 0;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if(!$this->stripped && $item instanceof Axe){
			$item->applyDamage(1);
			$this->stripped = true;
			$this->position->getWorld()->setBlock($this->position, $this);
			$this->position->getWorld()->addSound($this->position, new ItemUseOnBlockSound($this));
			return true;
		}
		return false;
	}
}
