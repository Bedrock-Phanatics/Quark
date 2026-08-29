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

/**
 * Tags used by items and enchantments to determine which enchantments can be applied to which items.
 * Some tags may contain other tags.
 * @see ItemEnchantmentTagRegistry
 */
final class ItemEnchantmentTags{
	public const ALL = "all";
	public const ARMOR = "armor";
	public const HELMET = "helmet";
	public const CHESTPLATE = "chestplate";
	public const LEGGINGS = "leggings";
	public const BOOTS = "boots";
	public const SHIELD = "shield";
	public const SWORD = "sword";
	public const TRIDENT = "trident";
	public const BOW = "bow";
	public const CROSSBOW = "crossbow";
	public const SHEARS = "shears";
	public const FLINT_AND_STEEL = "flint_and_steel";
	public const BLOCK_TOOLS = "block_tools";
	public const AXE = "axe";
	public const PICKAXE = "pickaxe";
	public const SHOVEL = "shovel";
	public const HOE = "hoe";
	public const FISHING_ROD = "fishing_rod";
	public const CARROT_ON_STICK = "carrot_on_stick";
	public const COMPASS = "compass";
	public const MASK = "mask";
	public const ELYTRA = "elytra";
	public const BRUSH = "brush";
	public const WEAPONS = "weapons";
}
