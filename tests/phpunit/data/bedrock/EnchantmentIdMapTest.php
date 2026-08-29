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

use PHPUnit\Framework\TestCase;
use quark\item\enchantment\VanillaEnchantments;

class EnchantmentIdMapTest extends TestCase{

	public function testAllEnchantsMapped() : void{
		foreach(VanillaEnchantments::getAll() as $enchantment){
			$id = EnchantmentIdMap::getInstance()->toId($enchantment);
			$enchantment2 = EnchantmentIdMap::getInstance()->fromId($id);
			self::assertTrue($enchantment === $enchantment2);
		}
	}
}
