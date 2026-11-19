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

use quark\block\utils\BellAttachmentType;
use quark\block\utils\HorizontalFacing;
use quark\block\utils\HorizontalFacingTrait;
use quark\block\utils\SupportType;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;
use quark\math\Facing;
use quark\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;

/**
 * Grindstones attach like bells, so they share the attachment type. Vanilla never uses TWO_WALLS for grindstones.
 * TODO: Implement the grindstone UI for repairing items and removing enchantments.
 */
class Grindstone extends Transparent implements HorizontalFacing{
	use HorizontalFacingTrait;

	public const int DEFAULT_FACING = Facing::SOUTH;

	protected BellAttachmentType $attachmentType = BellAttachmentType::FLOOR;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->enum($this->attachmentType);
		$w->horizontalFacing($this->facing);
	}

	public function getAttachmentType() : BellAttachmentType{ return $this->attachmentType; }

	/** @return $this */
	public function setAttachmentType(BellAttachmentType $attachmentType) : self{
		$this->attachmentType = $attachmentType;
		return $this;
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	private function getSupportFace() : int{
		return match($this->attachmentType){
			BellAttachmentType::FLOOR => Facing::DOWN,
			BellAttachmentType::CEILING => Facing::UP,
			BellAttachmentType::ONE_WALL, BellAttachmentType::TWO_WALLS => Facing::opposite($this->facing),
		};
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($face === Facing::UP || $face === Facing::DOWN){
			$this->attachmentType = $face === Facing::UP ? BellAttachmentType::FLOOR : BellAttachmentType::CEILING;
			if($player !== null){
				$this->facing = Facing::opposite($player->getHorizontalFacing());
			}
		}else{
			$this->attachmentType = BellAttachmentType::ONE_WALL;
			$this->facing = $face;
		}
		if($blockReplace->getAdjacentSupportType($this->getSupportFace()) === SupportType::NONE){
			return false;
		}
		return parent::place($tx, $item, $blockReplace, $face, $clickVector, $player);
	}

	public function onNearbyBlockChange() : void{
		if($this->getAdjacentSupportType($this->getSupportFace()) === SupportType::NONE){
			$this->position->getWorld()->useBreakOn($this->position);
		}
	}
}
