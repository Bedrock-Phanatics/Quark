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
 * Container for enchantment data applied to items.
 *
 * Note: This class is assumed to be immutable. Consider this before making alterations.
 */
final class EnchantmentInstance{
	public function __construct(
		private Enchantment $enchantment,
		private int $level = 1
	){}

	/**
	 * Returns the type of this enchantment.
	 */
	public function getType() : Enchantment{
		return $this->enchantment;
	}

	/**
	 * Returns the level of the enchantment.
	 */
	public function getLevel() : int{
		return $this->level;
	}
}
