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
 * Represents an option on the enchanting table menu.
 * If selected, all the enchantments in the option will be applied to the item.
 */
class EnchantingOption{

	/**
	 * @param EnchantmentInstance[] $enchantments
	 */
	public function __construct(
		private int $requiredXpLevel,
		private string $displayName,
		private array $enchantments
	){}

	/**
	 * Returns the minimum amount of XP levels required to select this enchantment option.
	 * It's NOT the number of XP levels that will be subtracted after enchanting.
	 */
	public function getRequiredXpLevel() : int{
		return $this->requiredXpLevel;
	}

	/**
	 * Returns the name that will be translated to the 'Standard Galactic Alphabet' client-side.
	 * This can be any arbitrary text string, since the vanilla client cannot read the text anyway.
	 * Example: 'bless creature range free'.
	 */
	public function getDisplayName() : string{
		return $this->displayName;
	}

	/**
	 * Returns the enchantments that will be applied to the item when this option is clicked.
	 *
	 * @return EnchantmentInstance[]
	 */
	public function getEnchantments() : array{
		return $this->enchantments;
	}
}
