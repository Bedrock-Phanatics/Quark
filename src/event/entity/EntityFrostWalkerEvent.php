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

namespace quark\event\entity;

use quark\block\Block;
use quark\block\Liquid;
use quark\entity\Living;
use quark\event\Cancellable;
use quark\event\CancellableTrait;

/**
 * Called when an entity moves horizontally while wearing boots enchanted with Frost Walker.
 *
 * @phpstan-extends EntityEvent<Living>
 */
class EntityFrostWalkerEvent extends EntityEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Living $entity,
		private int $radius,
		private Liquid $liquid,
		private Block $targetBlock
	){
		$this->entity = $entity;
	}

	public function getRadius() : int{
		return $this->radius;
	}

	public function setRadius(int $radius) : void{
		$this->radius = $radius;
	}

	/**
	 * Returns the liquid that gets frozen
	 */
	public function getLiquid() : Liquid{
		return $this->liquid;
	}

	/**
	 * Sets the liquid that gets frozen
	 */
	public function setLiquid(Liquid $liquid) : void{
		$this->liquid = $liquid;
	}

	/**
	 * Returns the block that replaces the liquid
	 */
	public function getTargetBlock() : Block{
		return $this->targetBlock;
	}

	/**
	 * Sets the block that replaces the liquid
	 */
	public function setTargetBlock(Block $targetBlock) : void{
		$this->targetBlock = $targetBlock;
	}
}
