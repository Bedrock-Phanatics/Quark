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

use quark\block\utils\SignLikeRotation;
use quark\block\utils\SignLikeRotationTrait;
use quark\block\utils\StaticSupportTrait;
use quark\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;

final class CeilingCenterHangingSign extends BaseSign implements SignLikeRotation{
	use SignLikeRotationTrait;
	use StaticSupportTrait;

	protected function getSupportingFace() : int{
		return Facing::UP;
	}

	//TODO: duplicated code :(
	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($face !== Facing::DOWN){
			return false;
		}

		if($player !== null){
			$this->rotation = self::getRotationFromYaw($player->getLocation()->getYaw());
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	private function canBeSupportedAt(Block $block) : bool{
		$supportBlock = $block->getSide(Facing::UP);
		return
			$supportBlock->getSupportType(Facing::DOWN)->hasCenterSupport() ||
			$supportBlock->hasTypeTag(BlockTypeTags::HANGING_SIGN);
	}

	protected function getFacingDegrees() : float{
		return $this->rotation * 22.5;
	}
}
