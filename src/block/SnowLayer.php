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

use quark\block\utils\BlockEventHelper;
use quark\block\utils\Fallable;
use quark\block\utils\FallableTrait;
use quark\block\utils\SupportType;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;
use quark\item\VanillaItems;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;
use function floor;
use function max;

class SnowLayer extends Flowable implements Fallable{
	use FallableTrait;

	public const MIN_LAYERS = 1;
	public const MAX_LAYERS = 8;

	protected int $layers = self::MIN_LAYERS;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(self::MIN_LAYERS, self::MAX_LAYERS, $this->layers);
	}

	public function getLayers() : int{ return $this->layers; }

	/** @return $this */
	public function setLayers(int $layers) : self{
		if($layers < self::MIN_LAYERS || $layers > self::MAX_LAYERS){
			throw new \InvalidArgumentException("Layers must be in range " . self::MIN_LAYERS . " ... " . self::MAX_LAYERS);
		}
		$this->layers = $layers;
		return $this;
	}

	public function canBeReplaced() : bool{
		return $this->layers < self::MAX_LAYERS;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->trim(Facing::UP, (self::MAX_LAYERS - $this->layers + 1) / 8)];
	}

	public function getSupportType(int $facing) : SupportType{
		if(!$this->canBeReplaced()){
			return SupportType::FULL;
		}
		return SupportType::NONE;
	}

	private function canBeSupportedAt(Block $block) : bool{
		return $block->getAdjacentSupportType(Facing::DOWN) === SupportType::FULL;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($blockReplace instanceof SnowLayer){
			if($blockReplace->layers >= self::MAX_LAYERS){
				return false;
			}
			$this->layers = $blockReplace->layers + 1;
		}
		if($this->canBeSupportedAt($blockReplace)){
			return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
		}

		return false;
	}

	public function ticksRandomly() : bool{
		return true;
	}

	public function onRandomTick() : void{
		$world = $this->position->getWorld();
		if($world->getBlockLightAt($this->position->x, $this->position->y, $this->position->z) >= 12){
			BlockEventHelper::melt($this, VanillaBlocks::AIR());
		}
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [
			VanillaItems::SNOWBALL()->setCount(max(1, (int) floor($this->layers / 2)))
		];
	}
}
