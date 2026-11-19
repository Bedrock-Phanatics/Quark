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
use quark\math\Facing;

/**
 * Replaceable ground vegetation that only drops when sheared, like the bush and dry grass.
 * TODO: Implement bone meal (bushes spread, short dry grass grows into tall dry grass).
 */
class Shrub extends Flowable{
	use StaticSupportTrait;

	/**
	 * @param \Closure(Block) : bool $supportedOn
	 * @phpstan-param \Closure(Block) : bool $supportedOn
	 */
	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo, private \Closure $supportedOn){
		parent::__construct($idInfo, $name, $typeInfo);
	}

	public function canBeReplaced() : bool{
		return true;
	}

	private function canBeSupportedAt(Block $block) : bool{
		return ($this->supportedOn)($block->getSide(Facing::DOWN));
	}

	public function getFlameEncouragement() : int{
		return 60;
	}

	public function getFlammability() : int{
		return 100;
	}
}
