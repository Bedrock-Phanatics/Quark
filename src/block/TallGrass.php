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
use quark\block\utils\TallGrassTrait;
use quark\item\Fertilizer;
use quark\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;

class TallGrass extends Flowable{
	use TallGrassTrait;
	use StaticSupportTrait;

	/** @phpstan-var \Closure() : DoublePlant|null */
	private ?\Closure $doublePlantVariant;

	/**
	 * @phpstan-param \Closure() : DoublePlant|null $doublePlantVariant
	 */
	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo, ?\Closure $doublePlantVariant = null){
		parent::__construct($idInfo, $name, $typeInfo);
		$this->doublePlantVariant = $doublePlantVariant;
	}

	private function canBeSupportedAt(Block $block) : bool{
		$supportBlock = $block->getSide(Facing::DOWN);
		return $supportBlock->hasTypeTag(BlockTypeTags::DIRT) || $supportBlock->hasTypeTag(BlockTypeTags::MUD);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		$world = $this->position->getWorld();
		$upPos = $this->position->getSide(Facing::UP);
		if(!$world->isInWorld($upPos->getFloorX(), $upPos->getFloorY(), $upPos->getFloorZ()) || $this->getSide(Facing::UP)->getTypeId() !== BlockTypeIds::AIR){
			return false;
		}

		if($item instanceof Fertilizer && ($doubleVariant = $this->getDoublePlantVariant()) !== null){
			$bottom = (clone $doubleVariant)->setTop(false);
			$top = (clone $doubleVariant)->setTop(true);
			$world->setBlock($this->position, $bottom);
			$world->setBlock($this->position->getSide(Facing::UP), $top);
			$item->pop();

			return true;
		}

		return false;
	}

	private function getDoublePlantVariant() : ?DoublePlant{
		return $this->doublePlantVariant !== null ? ($this->doublePlantVariant)() : null;
	}
}
