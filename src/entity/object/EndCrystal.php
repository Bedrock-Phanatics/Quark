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

namespace quark\entity\object;

use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use quark\entity\Entity;
use quark\entity\EntitySizeInfo;
use quark\entity\Explosive;
use quark\event\entity\EntityDamageEvent;
use quark\event\entity\EntityPreExplodeEvent;
use quark\item\Item;
use quark\item\VanillaItems;
use quark\network\mcpe\protocol\EntityMetadata;
use quark\network\mcpe\protocol\types\entity\EntityIds;
use quark\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use quark\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use quark\utils\Utils;
use quark\world\Explosion;

class EndCrystal extends Entity implements Explosive{
	private const TAG_SHOWBASE = "ShowBottom"; //TAG_Byte

	private const TAG_BLOCKTARGET_X = "BlockTargetX"; //TAG_Int
	private const TAG_BLOCKTARGET_Y = "BlockTargetY"; //TAG_Int
	private const TAG_BLOCKTARGET_Z = "BlockTargetZ"; //TAG_Int

	public static function getNetworkTypeId() : string{ return EntityIds::ENDER_CRYSTAL; }

	protected bool $showBase = false;
	protected ?Vector3 $beamTarget = null;

	private bool $primed = false;

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(2.0, 2.0); }

	protected function getInitialDragMultiplier() : float{ return 1.0; }

	protected function getInitialGravity() : float{ return 0.0; }

	public function isFireProof() : bool{
		return true;
	}

	public function getPickedItem() : ?Item{
		return VanillaItems::END_CRYSTAL();
	}

	public function showBase() : bool{
		return $this->showBase;
	}

	public function setShowBase(bool $showBase) : void{
		$this->showBase = $showBase;
		$this->networkPropertiesDirty = true;
	}

	public function getBeamTarget() : ?Vector3{
		return $this->beamTarget;
	}

	public function setBeamTarget(?Vector3 $beamTarget) : void{
		if($beamTarget !== null){
			Utils::checkBlockPositionFitsInt32($beamTarget);
		}
		$this->beamTarget = $beamTarget;
		$this->networkPropertiesDirty = true;
	}

	public function attack(EntityDamageEvent $source) : void{
		parent::attack($source);
		if(
			$source->getCause() !== EntityDamageEvent::CAUSE_VOID &&
			!$source->isCancelled()
		){
			$this->primed = true;
		}
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);

		$this->setMaxHealth(1);
		$this->setHealth(1);

		$this->setShowBase($nbt->getByte(self::TAG_SHOWBASE, 0) === 1);

		if(
			($beamXTag = $nbt->getTag(self::TAG_BLOCKTARGET_X)) instanceof IntTag &&
			($beamYTag = $nbt->getTag(self::TAG_BLOCKTARGET_Y)) instanceof IntTag &&
			($beamZTag = $nbt->getTag(self::TAG_BLOCKTARGET_Z)) instanceof IntTag
		){
			$this->setBeamTarget(new Vector3($beamXTag->getValue(), $beamYTag->getValue(), $beamZTag->getValue()));
		}
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();

		$nbt->setByte(self::TAG_SHOWBASE, $this->showBase ? 1 : 0);
		if($this->beamTarget !== null){
			$nbt->setInt(self::TAG_BLOCKTARGET_X, $this->beamTarget->getFloorX());
			$nbt->setInt(self::TAG_BLOCKTARGET_Y, $this->beamTarget->getFloorY());
			$nbt->setInt(self::TAG_BLOCKTARGET_Z, $this->beamTarget->getFloorZ());
		}
		return $nbt;
	}

	protected function onDeathUpdate(int $tickDiff) : bool{
		if($this->primed){
			$this->explode();
		}
		return true;
	}

	public function explode() : void{
		$ev = new EntityPreExplodeEvent($this, 6);
		$ev->call();
		if(!$ev->isCancelled()){
			$explosion = new Explosion($this->getPosition(), $ev->getRadius(), $this, $ev->getFireChance());
			if($ev->isBlockBreaking()){
				$explosion->explodeA();
			}
			$explosion->explodeB();
		}
	}

	protected function syncNetworkData(EntityMetadata $properties) : void{
		parent::syncNetworkData($properties);

		$properties->setGenericFlag(EntityMetadataFlags::SHOWBASE, $this->showBase);
		$properties->setBlockPos(EntityMetadataProperties::BLOCK_TARGET, $this->beamTarget ?? Vector3::zero());
	}
}
