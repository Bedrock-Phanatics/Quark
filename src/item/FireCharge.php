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

use quark\block\Block;
use quark\block\BlockTypeIds;
use quark\block\VanillaBlocks;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\sound\BlazeShootSound;

class FireCharge extends Item{

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		if($blockReplace->getTypeId() === BlockTypeIds::AIR){
			$world = $player->getWorld();
			$world->setBlock($blockReplace->getPosition(), VanillaBlocks::FIRE());
			$world->addSound($blockReplace->getPosition()->add(0.5, 0.5, 0.5), new BlazeShootSound());

			$this->pop();

			return ItemUseResult::SUCCESS;
		}

		return ItemUseResult::NONE;
	}
}
