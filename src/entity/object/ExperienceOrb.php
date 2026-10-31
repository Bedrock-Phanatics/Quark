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
use pocketmine\utils\Limits;
use quark\entity\Entity;
use quark\entity\EntitySizeInfo;
use quark\entity\Human;
use quark\entity\Location;
use quark\network\mcpe\protocol\EntityMetadata;
use quark\network\mcpe\protocol\types\entity\EntityIds;
use quark\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use quark\player\Player;
use function max;
use function min;
use function sqrt;

class ExperienceOrb extends Entity{

	public static function getNetworkTypeId() : string{ return EntityIds::XP_ORB; }

	public const TAG_VALUE_PC = "Value"; //short
	public const TAG_VALUE_PE = "experience value"; //int (WTF?)
	private const TAG_AGE = "Age"; //TAG_Short
	public const TAG_COUNT = "Count"; //TAG_Int

	//orbs only merge within the same ID group, so a big drop still shows up as a cloud like it does in Java
	public const MERGE_GROUPS = 40;

	/** Max distance an orb will follow a player across. */
	public const MAX_TARGET_DISTANCE = 8.0;

	/** Split sizes used for dropping experience orbs. */
	public const ORB_SPLIT_SIZES = [2477, 1237, 617, 307, 149, 73, 37, 17, 7, 3, 1]; //This is indexed biggest to smallest so that we can return as soon as we found the biggest value.

	public const DEFAULT_DESPAWN_DELAY = 6000;
	public const NEVER_DESPAWN = -1;
	public const MAX_DESPAWN_DELAY = 32767 + self::DEFAULT_DESPAWN_DELAY; //max value storable by mojang NBT :(

	/**
	 * Returns the largest size of normal XP orb that will be spawned for the specified amount of XP. Used to split XP
	 * up into multiple orbs when an amount of XP is dropped.
	 */
	public static function getMaxOrbSize(int $amount) : int{
		foreach(self::ORB_SPLIT_SIZES as $split){
			if($amount >= $split){
				return $split;
			}
		}

		return 1;
	}

	/**
	 * Splits the specified amount of XP into an array of acceptable XP orb sizes.
	 *
	 * @return int[]
	 */
	public static function splitIntoOrbSizes(int $amount) : array{
		$result = [];

		while($amount > 0){
			$size = self::getMaxOrbSize($amount);
			$result[] = $size;
			$amount -= $size;
		}

		return $result;
	}

	/** Ticker used for determining interval in which to look for new target players. */
	protected int $lookForTargetTime = 0;

	/** Runtime entity ID of the player this XP orb is targeting. */
	protected ?int $targetPlayerRuntimeId = null;

	protected int $xpValue;
	private int $count = 1;

	private int $despawnDelay = self::DEFAULT_DESPAWN_DELAY;

	public function __construct(Location $location, int $xpValue, ?CompoundTag $nbt = null){
		self::validateXpValue($xpValue);
		$this->xpValue = $xpValue;
		parent::__construct($location, $nbt);
	}

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(0.25, 0.25); }

	protected function getInitialDragMultiplier() : float{ return 0.02; }

	protected function getInitialGravity() : float{ return 0.04; }

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);

		$age = $nbt->getShort(self::TAG_AGE, 0);
		if($age === -32768){
			$this->despawnDelay = self::NEVER_DESPAWN;
		}else{
			$this->despawnDelay = max(0, self::DEFAULT_DESPAWN_DELAY - $age);
		}
		$this->count = max(1, $nbt->getInt(self::TAG_COUNT, 1));
	}

	public function saveNBT() : CompoundTag{
		$nbt = parent::saveNBT();

		if($this->despawnDelay === self::NEVER_DESPAWN){
			$age = -32768;
		}else{
			$age = self::DEFAULT_DESPAWN_DELAY - $this->despawnDelay;
		}
		$nbt->setShort(self::TAG_AGE, $age);

		$nbt->setShort(self::TAG_VALUE_PC, min($this->getXpValue(), Limits::INT16_MAX));
		$nbt->setInt(self::TAG_VALUE_PE, $this->getXpValue());
		if($this->count > 1){
			$nbt->setInt(self::TAG_COUNT, $this->count);
		}

		return $nbt;
	}

	/**
	 * How many orbs were merged into this one, so it holds getXpValue() * getCount() experience in total.
	 */
	public function getCount() : int{
		return $this->count;
	}

	public function setCount(int $count) : void{
		if($count < 1 || $count > Limits::INT32_MAX){
			throw new \InvalidArgumentException("Orb count must be in range 1 - " . Limits::INT32_MAX . ", got $count");
		}
		$this->count = $count;
	}

	/**
	 * @internal
	 */
	public function absorbNewOrb() : void{
		++$this->count;
		if($this->despawnDelay !== self::NEVER_DESPAWN){
			$this->despawnDelay = self::DEFAULT_DESPAWN_DELAY;
		}
	}

	public function canMergeWith(ExperienceOrb $other) : bool{
		return
			$other !== $this &&
			!$other->isFlaggedForDespawn() &&
			!$this->isFlaggedForDespawn() &&
			$other->xpValue === $this->xpValue &&
			($other->getId() - $this->getId()) % self::MERGE_GROUPS === 0;
	}

	protected function mergeWithNearbyOrbs() : void{
		foreach($this->getWorld()->getNearbyEntities($this->boundingBox->expandedCopy(0.5, 0.5, 0.5), $this) as $entity){
			if(!$entity instanceof ExperienceOrb || !$this->canMergeWith($entity) || $this->count + $entity->count > Limits::INT32_MAX){
				continue;
			}
			$this->count += $entity->count;
			if($this->despawnDelay !== self::NEVER_DESPAWN && ($entity->despawnDelay === self::NEVER_DESPAWN || $entity->despawnDelay > $this->despawnDelay)){
				$this->despawnDelay = $entity->despawnDelay;
			}
			$entity->flagForDespawn();
		}
	}

	public function getDespawnDelay() : int{ return $this->despawnDelay; }

	public function setDespawnDelay(int $despawnDelay) : void{
		if(($despawnDelay < 0 || $despawnDelay > self::MAX_DESPAWN_DELAY) && $despawnDelay !== self::NEVER_DESPAWN){
			throw new \InvalidArgumentException("Despawn ticker must be in range 0 ... " . self::MAX_DESPAWN_DELAY . " or " . self::NEVER_DESPAWN . ", got $despawnDelay");
		}
		$this->despawnDelay = $despawnDelay;
	}

	public function getXpValue() : int{
		return $this->xpValue;
	}

	private static function validateXpValue(int $amount) : void{
		if($amount <= 0 || $amount > Limits::INT32_MAX){
			throw new \InvalidArgumentException("XP amount must be in range 1 - " . Limits::INT32_MAX . ", got $amount");
		}
	}

	public function setXpValue(int $amount) : void{
		self::validateXpValue($amount);
		$this->xpValue = $amount;
		$this->networkPropertiesDirty = true;
	}

	public function hasTargetPlayer() : bool{
		return $this->targetPlayerRuntimeId !== null;
	}

	public function getTargetPlayer() : ?Human{
		if($this->targetPlayerRuntimeId === null){
			return null;
		}

		$entity = $this->getWorld()->getEntity($this->targetPlayerRuntimeId);
		//TODO: HACK! We really shouldn't be keeping disconnected players (and generally flagged-for-despawn entities)
		//in the world's entity table, but changing that is too risky for a hotfix. This workaround will do for now.
		if($entity instanceof Human && !$entity->isFlaggedForDespawn()){
			return $entity;
		}

		return null;
	}

	public function setTargetPlayer(?Human $player) : void{
		$this->targetPlayerRuntimeId = $player !== null ? $player->getId() : null;
	}

	protected function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);

		if($this->despawnDelay !== self::NEVER_DESPAWN){
			$this->despawnDelay -= $tickDiff;
			if($this->despawnDelay <= 0){
				$this->flagForDespawn();
				return true;
			}
		}

		$currentTarget = $this->getTargetPlayer();
		if($currentTarget !== null && (!$currentTarget->isAlive() || !$currentTarget->getXpManager()->canAttractXpOrbs() || $currentTarget->location->distanceSquared($this->location) > self::MAX_TARGET_DISTANCE ** 2)){
			$currentTarget = null;
		}

		if($this->lookForTargetTime >= 20){
			$this->mergeWithNearbyOrbs();
			if($currentTarget === null){
				$newTarget = $this->getWorld()->getNearestEntity($this->location, self::MAX_TARGET_DISTANCE, Human::class);

				if($newTarget instanceof Human && !($newTarget instanceof Player && $newTarget->isSpectator()) && $newTarget->getXpManager()->canAttractXpOrbs()){
					$currentTarget = $newTarget;
				}
			}

			$this->lookForTargetTime = 0;
		}else{
			$this->lookForTargetTime += $tickDiff;
		}

		$this->setTargetPlayer($currentTarget);

		if($currentTarget !== null){
			//same maths as the Vector3 version, written out to skip the allocations since it runs every tick
			$target = $currentTarget->getPosition();
			$vx = ($target->x + 0 + -$this->location->x) / self::MAX_TARGET_DISTANCE;
			$vy = ($target->y + $currentTarget->getEyeHeight() / 2 + -$this->location->y) / self::MAX_TARGET_DISTANCE;
			$vz = ($target->z + 0 + -$this->location->z) / self::MAX_TARGET_DISTANCE;

			$distance = $vx * $vx + $vy * $vy + $vz * $vz;
			if($distance < 1){
				$pull = 0.2 * (1 - sqrt($distance)) ** 2;
				if($distance > 0){
					$length = sqrt($distance);
					$vx /= $length;
					$vy /= $length;
					$vz /= $length;
				}else{
					$vx = $vy = $vz = 0;
				}
				$this->motion = new Vector3($this->motion->x + $vx * $pull, $this->motion->y + $vy * $pull, $this->motion->z + $vz * $pull);
			}

			if($currentTarget->getXpManager()->canPickupXp() && $this->boundingBox->intersectsWith($currentTarget->getBoundingBox())){
				//merged orbs still get picked up one at a time
				if(--$this->count <= 0){
					$this->flagForDespawn();
				}

				$currentTarget->getXpManager()->onPickupXp($this->getXpValue());
			}
		}

		return $hasUpdate;
	}

	protected function tryChangeMovement() : void{
		$this->checkObstruction($this->location->x, $this->location->y, $this->location->z);
		parent::tryChangeMovement();
	}

	public function canBeCollidedWith() : bool{
		return false;
	}

	protected function syncNetworkData(EntityMetadata $properties) : void{
		parent::syncNetworkData($properties);

		$properties->setInt(EntityMetadataProperties::EXPERIENCE_VALUE, $this->xpValue);
	}
}
