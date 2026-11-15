<?php

declare(strict_types=1);

namespace quark\block\custom;

use quark\block\Block;
use quark\block\BlockTypeTags;
use quark\block\Flowable;
use quark\block\utils\StaticSupportTrait;
use quark\math\Facing;

/**
 * Non-colliding plant that needs dirt or mud below, like a flower.
 */
class CustomPlant extends Flowable{
	use StaticSupportTrait;

	private function canBeSupportedAt(Block $block) : bool{
		$support = $block->getSide(Facing::DOWN);
		return $support->hasTypeTag(BlockTypeTags::DIRT) || $support->hasTypeTag(BlockTypeTags::MUD);
	}

	public function getFlameEncouragement() : int{ return 60; }

	public function getFlammability() : int{ return 100; }
}
