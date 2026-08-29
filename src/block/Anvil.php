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

use quark\block\inventory\AnvilInventory;
use quark\block\utils\Fallable;
use quark\block\utils\FallableTrait;
use quark\block\utils\HorizontalFacing;
use quark\block\utils\HorizontalFacingTrait;
use quark\block\utils\SupportType;
use quark\data\runtime\RuntimeDataDescriber;
use quark\entity\object\FallingBlock;
use quark\item\Item;
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\utils\Utils;
use quark\world\BlockTransaction;
use quark\world\sound\AnvilFallSound;
use quark\world\sound\Sound;
use function round;

class Anvil extends Transparent implements Fallable, HorizontalFacing{
	use FallableTrait;
	use HorizontalFacingTrait;

	public const UNDAMAGED = 0;
	public const SLIGHTLY_DAMAGED = 1;
	public const VERY_DAMAGED = 2;

	private int $damage = self::UNDAMAGED;

	public function describeBlockItemState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(self::UNDAMAGED, self::VERY_DAMAGED, $this->damage);
	}

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
	}

	public function getDamage() : int{ return $this->damage; }

	/** @return $this */
	public function setDamage(int $damage) : self{
		if($damage < self::UNDAMAGED || $damage > self::VERY_DAMAGED){
			throw new \InvalidArgumentException("Damage must be in range " . self::UNDAMAGED . " ... " . self::VERY_DAMAGED);
		}
		$this->damage = $damage;
		return $this;
	}

	protected function recalculateCollisionBoxes() : array{
		return [AxisAlignedBB::one()->squash(Facing::axis(Facing::rotateY($this->facing, false)), 1 / 8)];
	}

	public function getSupportType(int $facing) : SupportType{
		return SupportType::NONE;
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player instanceof Player){
			$player->setCurrentWindow(new AnvilInventory($this->position));
		}

		return true;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			$this->facing = Facing::rotateY($player->getHorizontalFacing(), false);
		}
		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onHitGround(FallingBlock $blockEntity) : bool{
		if(Utils::getRandomFloat() < 0.05 + (round($blockEntity->getFallDistance()) - 1) * 0.05){
			if($this->damage !== self::VERY_DAMAGED){
				$this->damage = $this->damage + 1;
			}else{
				return false;
			}
		}
		return true;
	}

	public function getFallDamagePerBlock() : float{
		return 2.0;
	}

	public function getMaxFallDamage() : float{
		return 40.0;
	}

	public function getLandSound() : ?Sound{
		return new AnvilFallSound();
	}
}
