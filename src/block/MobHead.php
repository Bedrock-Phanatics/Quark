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

use quark\block\tile\MobHead as TileMobHead;
use quark\block\utils\MobHeadType;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\BlockTransaction;
use function assert;
use function floor;

class MobHead extends Flowable{
	public const MIN_ROTATION = 0;
	public const MAX_ROTATION = 15;

	protected MobHeadType $mobHeadType = MobHeadType::SKELETON;

	protected int $facing = Facing::NORTH;
	protected int $rotation = self::MIN_ROTATION; //TODO: split this into floor skull and wall skull handling

	public function describeBlockItemState(RuntimeDataDescriber $w) : void{
		$w->enum($this->mobHeadType);
	}

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facingExcept($this->facing, Facing::DOWN);
	}

	public function readStateFromWorld() : Block{
		parent::readStateFromWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		if($tile instanceof TileMobHead){
			$this->mobHeadType = $tile->getMobHeadType();
			$this->rotation = $tile->getRotation();
		}

		return $this;
	}

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		//extra block properties storage hack
		$tile = $this->position->getWorld()->getTile($this->position);
		assert($tile instanceof TileMobHead);
		$tile->setRotation($this->rotation);
		$tile->setMobHeadType($this->mobHeadType);
	}

	public function getMobHeadType() : MobHeadType{
		return $this->mobHeadType;
	}

	/** @return $this */
	public function setMobHeadType(MobHeadType $mobHeadType) : self{
		$this->mobHeadType = $mobHeadType;
		return $this;
	}

	public function getFacing() : int{ return $this->facing; }

	/** @return $this */
	public function setFacing(int $facing) : self{
		if($facing === Facing::DOWN){
			throw new \InvalidArgumentException("Skull may not face DOWN");
		}
		$this->facing = $facing;
		return $this;
	}

	public function getRotation() : int{ return $this->rotation; }

	/** @return $this */
	public function setRotation(int $rotation) : self{
		if($rotation < self::MIN_ROTATION || $rotation > self::MAX_ROTATION){
			throw new \InvalidArgumentException("Rotation must be in range " . self::MIN_ROTATION . " ... " . self::MAX_ROTATION);
		}
		$this->rotation = $rotation;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		$collisionBox = AxisAlignedBB::one()
			->contract(0.25, 0, 0.25)
			->trim(Facing::UP, 0.5);
		if($this->facing !== Facing::UP){
			$collisionBox = $collisionBox
				->offsetTowards(Facing::opposite($this->facing), 0.25)
				->offsetTowards(Facing::UP, 0.25);
		}
		return [$collisionBox];
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($face === Facing::DOWN){
			return false;
		}

		$this->facing = $face;
		if($player !== null && $face === Facing::UP){
			$this->rotation = ((int) floor(($player->getLocation()->getYaw() * 16 / 360) + 0.5)) & 0xf;
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}
}
