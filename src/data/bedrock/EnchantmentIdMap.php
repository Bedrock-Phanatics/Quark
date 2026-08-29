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

namespace quark\data\bedrock;

use quark\item\enchantment\Enchantment;
use quark\item\enchantment\VanillaEnchantments;
use quark\utils\SingletonTrait;

/**
 * Handles translation of internal enchantment types to and from Minecraft: Bedrock IDs.
 */
final class EnchantmentIdMap{
	use SingletonTrait;
	/** @phpstan-use IntSaveIdMapTrait<Enchantment> */
	use IntSaveIdMapTrait;

	private function __construct(){
		$this->register(EnchantmentIds::PROTECTION, VanillaEnchantments::PROTECTION());
		$this->register(EnchantmentIds::FIRE_PROTECTION, VanillaEnchantments::FIRE_PROTECTION());
		$this->register(EnchantmentIds::FEATHER_FALLING, VanillaEnchantments::FEATHER_FALLING());
		$this->register(EnchantmentIds::BLAST_PROTECTION, VanillaEnchantments::BLAST_PROTECTION());
		$this->register(EnchantmentIds::PROJECTILE_PROTECTION, VanillaEnchantments::PROJECTILE_PROTECTION());
		$this->register(EnchantmentIds::THORNS, VanillaEnchantments::THORNS());
		$this->register(EnchantmentIds::RESPIRATION, VanillaEnchantments::RESPIRATION());
		$this->register(EnchantmentIds::AQUA_AFFINITY, VanillaEnchantments::AQUA_AFFINITY());

		$this->register(EnchantmentIds::SHARPNESS, VanillaEnchantments::SHARPNESS());
		//TODO: smite, bane of arthropods (these don't make sense now because their applicable mobs don't exist yet)

		$this->register(EnchantmentIds::KNOCKBACK, VanillaEnchantments::KNOCKBACK());
		$this->register(EnchantmentIds::FIRE_ASPECT, VanillaEnchantments::FIRE_ASPECT());

		$this->register(EnchantmentIds::EFFICIENCY, VanillaEnchantments::EFFICIENCY());
		$this->register(EnchantmentIds::FORTUNE, VanillaEnchantments::FORTUNE());
		$this->register(EnchantmentIds::SILK_TOUCH, VanillaEnchantments::SILK_TOUCH());
		$this->register(EnchantmentIds::UNBREAKING, VanillaEnchantments::UNBREAKING());

		$this->register(EnchantmentIds::POWER, VanillaEnchantments::POWER());
		$this->register(EnchantmentIds::PUNCH, VanillaEnchantments::PUNCH());
		$this->register(EnchantmentIds::FLAME, VanillaEnchantments::FLAME());
		$this->register(EnchantmentIds::INFINITY, VanillaEnchantments::INFINITY());

		$this->register(EnchantmentIds::MENDING, VanillaEnchantments::MENDING());

		$this->register(EnchantmentIds::VANISHING, VanillaEnchantments::VANISHING());

		$this->register(EnchantmentIds::SWIFT_SNEAK, VanillaEnchantments::SWIFT_SNEAK());

		$this->register(EnchantmentIds::FROST_WALKER, VanillaEnchantments::FROST_WALKER());
	}
}
