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
use quark\entity\Location;
use quark\entity\object\Painting;
use quark\entity\object\PaintingMotive;
use pocketmine\math\Axis;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\sound\PaintingPlaceSound;
use function array_rand;
use function count;

class PaintingItem extends Item{

	public function onInteractBlock(Player $player, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, array &$returnedItems) : ItemUseResult{
		if(Facing::axis($face) === Axis::Y){
			return ItemUseResult::NONE;
		}

		$motives = [];

		$totalDimension = 0;
		foreach(PaintingMotive::getAll() as $motive){
			$currentTotalDimension = $motive->getHeight() + $motive->getWidth();
			if($currentTotalDimension < $totalDimension){
				continue;
			}

			if(Painting::canFit($player->getWorld(), $blockReplace->getPosition(), $face, true, $motive)){
				if($currentTotalDimension > $totalDimension){
					$totalDimension = $currentTotalDimension;
					/*
					 * This drops all motive possibilities smaller than this
					 * We use the total of height + width to allow equal chance of horizontal/vertical paintings
					 * when there is an L-shape of space available.
					 */
					$motives = [];
				}

				$motives[] = $motive;
			}
		}

		if(count($motives) === 0){ //No space available
			return ItemUseResult::NONE;
		}

		/** @var PaintingMotive $motive */
		$motive = $motives[array_rand($motives)];

		$replacePos = $blockReplace->getPosition();
		$clickedPos = $blockClicked->getPosition();

		$entity = new Painting(Location::fromObject($replacePos, $replacePos->getWorld()), $clickedPos, $face, $motive);
		$this->pop();
		$entity->spawnToAll();

		$player->getWorld()->addSound($replacePos->add(0.5, 0.5, 0.5), new PaintingPlaceSound());
		return ItemUseResult::SUCCESS;
	}
}
