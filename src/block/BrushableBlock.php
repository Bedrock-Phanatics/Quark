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

use quark\block\utils\Fallable;
use quark\block\utils\FallableTrait;
use quark\data\runtime\RuntimeDataDescriber;
use quark\entity\object\FallingBlock;
use quark\item\Item;

/**
 * Suspicious sand and gravel. They drop nothing when broken; the buried loot is only obtained by brushing.
 * TODO: Implement brushing with a brush, which advances the progress and drops the item from the loot table.
 */
class BrushableBlock extends Opaque implements Fallable{
	use FallableTrait;

	public const MAX_BRUSHED_PROGRESS = 3;

	protected int $brushedProgress = 0;
	//TODO: Find out what vanilla uses this for; it is kept but doesn't change how the block falls.
	protected bool $hanging = true;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(0, self::MAX_BRUSHED_PROGRESS, $this->brushedProgress);
		$w->bool($this->hanging);
	}

	public function getBrushedProgress() : int{ return $this->brushedProgress; }

	/** @return $this */
	public function setBrushedProgress(int $progress) : self{
		if($progress < 0 || $progress > self::MAX_BRUSHED_PROGRESS){
			throw new \InvalidArgumentException("Brushed progress must be in range 0 ... " . self::MAX_BRUSHED_PROGRESS);
		}
		$this->brushedProgress = $progress;
		return $this;
	}

	public function isHanging() : bool{ return $this->hanging; }

	/** @return $this */
	public function setHanging(bool $hanging) : self{
		$this->hanging = $hanging;
		return $this;
	}

	public function getDrops(Item $item) : array{
		return [];
	}

	public function onHitGround(FallingBlock $blockEntity) : bool{
		//vanilla destroys these when they land, along with the buried item
		return false;
	}
}
