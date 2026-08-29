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

namespace quark\item\enchantment;

use quark\event\entity\EntityDamageEvent;
use quark\lang\Translatable;
use function array_fill_keys;
use function floor;

class ProtectionEnchantment extends Enchantment{
	protected float $typeModifier;
	/**
	 * @var true[]|null
	 * @phpstan-var array<int, true>
	 */
	protected ?array $applicableDamageTypes = null;

	/**
	 * ProtectionEnchantment constructor.
	 *
	 * @phpstan-param null|(\Closure(int $level) : int) $minEnchantingPower
	 * @phpstan-param list<int>|null $applicableDamageTypes
	 *
	 * @param int        $primaryItemFlags      @deprecated
	 * @param int        $secondaryItemFlags    @deprecated
	 * @param int[]|null $applicableDamageTypes EntityDamageEvent::CAUSE_* constants which this enchantment type applies to, or null if it applies to all types of damage.
	 * @param int        $enchantingPowerRange  Value used to calculate the maximum enchanting power (minEnchantingPower + enchantingPowerRange)
	 */
	public function __construct(Translatable|string $name, int $rarity, int $primaryItemFlags, int $secondaryItemFlags, int $maxLevel, float $typeModifier, ?array $applicableDamageTypes, ?\Closure $minEnchantingPower = null, int $enchantingPowerRange = 50){
		parent::__construct($name, $rarity, $primaryItemFlags, $secondaryItemFlags, $maxLevel, $minEnchantingPower, $enchantingPowerRange);

		$this->typeModifier = $typeModifier;
		if($applicableDamageTypes !== null){
			$this->applicableDamageTypes = array_fill_keys($applicableDamageTypes, true);
		}
	}

	/**
	 * Returns the multiplier by which this enchantment type's EPF increases with each enchantment level.
	 */
	public function getTypeModifier() : float{
		return $this->typeModifier;
	}

	/**
	 * Returns the base EPF this enchantment type offers for the given enchantment level.
	 */
	public function getProtectionFactor(int $level) : int{
		return (int) floor((6 + $level ** 2) * $this->typeModifier / 3);
	}

	/**
	 * Returns whether this enchantment type offers protection from the specified damage source's cause.
	 */
	public function isApplicable(EntityDamageEvent $event) : bool{
		return $this->applicableDamageTypes === null || isset($this->applicableDamageTypes[$event->getCause()]);
	}
}
