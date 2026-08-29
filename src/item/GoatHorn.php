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
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\sound\GoatHornSound;

class GoatHorn extends Item implements Releasable{

	private GoatHornType $goatHornType = GoatHornType::PONDER;

	protected function describeState(RuntimeDataDescriber $w) : void{
		$w->enum($this->goatHornType);
	}

	public function getHornType() : GoatHornType{ return $this->goatHornType; }

	/**
	 * @return $this
	 */
	public function setHornType(GoatHornType $type) : self{
		$this->goatHornType = $type;
		return $this;
	}

	public function getMaxStackSize() : int{
		return 1;
	}

	public function getCooldownTicks() : int{
		return 140;
	}

	public function getCooldownTag() : ?string{
		return ItemCooldownTags::GOAT_HORN;
	}

	public function canStartUsingItem(Player $player) : bool{
		return true;
	}

	public function onClickAir(Player $player, Vector3 $directionVector, array &$returnedItems) : ItemUseResult{
		$position = $player->getPosition();
		$position->getWorld()->addSound($position, new GoatHornSound($this->goatHornType));

		return ItemUseResult::SUCCESS;
	}
}
