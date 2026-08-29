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

use quark\entity\Entity;
use quark\event\Cancellable;
use quark\event\CancellableTrait;
use function array_sum;
use function max;

/**
 * Called when an entity takes damage.
 * @phpstan-extends EntityEvent<Entity>
 */
class EntityDamageEvent extends EntityEvent implements Cancellable{
	use CancellableTrait;

	public const MODIFIER_ARMOR = 1;
	public const MODIFIER_STRENGTH = 2;
	public const MODIFIER_WEAKNESS = 3;
	public const MODIFIER_RESISTANCE = 4;
	public const MODIFIER_ARMOR_ENCHANTMENTS = 5;
	public const MODIFIER_CRITICAL = 6;
	public const MODIFIER_TOTEM = 7;
	public const MODIFIER_WEAPON_ENCHANTMENTS = 8;
	public const MODIFIER_PREVIOUS_DAMAGE_COOLDOWN = 9;
	public const MODIFIER_ARMOR_HELMET = 10;

	public const MODIFIER_PROTECTED_DAMAGE = 11;

	public const CAUSE_CONTACT = 0;
	public const CAUSE_ENTITY_ATTACK = 1;
	public const CAUSE_PROJECTILE = 2;
	public const CAUSE_SUFFOCATION = 3;
	public const CAUSE_FALL = 4;
	public const CAUSE_FIRE = 5;
	public const CAUSE_FIRE_TICK = 6;
	public const CAUSE_LAVA = 7;
	public const CAUSE_DROWNING = 8;
	public const CAUSE_BLOCK_EXPLOSION = 9;
	public const CAUSE_ENTITY_EXPLOSION = 10;
	public const CAUSE_VOID = 11;
	public const CAUSE_SUICIDE = 12;
	public const CAUSE_MAGIC = 13;
	public const CAUSE_CUSTOM = 14;
	public const CAUSE_STARVATION = 15;
	public const CAUSE_FALLING_BLOCK = 16;

	private float $baseDamage;
	private float $originalBase;

	/** @var float[] */
	private array $originals;
	private int $attackCooldown = 10;

	private float $protectedDamage = 0.0;

	/**
	 * @param float[] $modifiers
	 */
	public function __construct(
		Entity $entity,
		private int $cause,
		float $damage,
		private array $modifiers = []
	){
		$this->entity = $entity;
		$this->baseDamage = $this->originalBase = $damage;
		$this->originals = $modifiers;
	}

	public function getCause() : int{
		return $this->cause;
	}

	public function getBaseDamage() : float{
		return $this->baseDamage;
	}

	public function setBaseDamage(float $damage) : void{
		$this->baseDamage = $damage;
	}

	public function getOriginalBaseDamage() : float{
		return $this->originalBase;
	}

	/**
	 * @return float[]
	 */
	public function getOriginalModifiers() : array{
		return $this->originals;
	}

	public function getOriginalModifier(int $type) : float{
		return $this->originals[$type] ?? 0.0;
	}

	/**
	 * @return float[]
	 */
	public function getModifiers() : array{
		return $this->modifiers;
	}

	public function getModifier(int $type) : float{
		return $this->modifiers[$type] ?? 0.0;
	}

	public function setModifier(float $damage, int $type) : void{
		$this->modifiers[$type] = $damage;
	}

	public function isApplicable(int $type) : bool{
		return isset($this->modifiers[$type]);
	}

	public function addProtectedDamage(float $damage) : void{
		$this->protectedDamage += $damage;
	}

	public function setProtectedDamage(float $damage) : void{
		$this->protectedDamage = $damage;
	}

	public function getProtectedDamage() : float{
		return $this->protectedDamage;
	}

	public function clearProtectedDamage() : void{
		$this->protectedDamage = 0.0;
	}

	public function getFinalDamage() : float{
		$normalDamage = $this->baseDamage + array_sum($this->modifiers);

		$finalProtectedDamage = $this->protectedDamage;
		if(isset($this->modifiers[self::MODIFIER_PROTECTED_DAMAGE])){
			$finalProtectedDamage += $this->modifiers[self::MODIFIER_PROTECTED_DAMAGE];
		}

		return max(0, $normalDamage + max(0, $finalProtectedDamage));
	}

	public function getFinalDamageWithoutProtected() : float{
		$modifiers = $this->modifiers;
		unset($modifiers[self::MODIFIER_PROTECTED_DAMAGE]);
		return max(0, $this->baseDamage + array_sum($modifiers));
	}

	public function canBeReducedByArmor() : bool{
		return match ($this->cause) {
			self::CAUSE_FIRE_TICK, self::CAUSE_SUFFOCATION, self::CAUSE_DROWNING, self::CAUSE_STARVATION, self::CAUSE_FALL, self::CAUSE_VOID, self::CAUSE_MAGIC, self::CAUSE_SUICIDE => false,
			default => true,
		};

	}

	public function getAttackCooldown() : int{
		return $this->attackCooldown;
	}

	public function setAttackCooldown(int $attackCooldown) : void{
		$this->attackCooldown = $attackCooldown;
	}
}
