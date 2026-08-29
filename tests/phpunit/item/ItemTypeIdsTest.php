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

namespace quark\item;

use PHPUnit\Framework\TestCase;
use quark\utils\Utils;
use function array_unique;
use function max;

class ItemTypeIdsTest extends TestCase{

	public function testFirstUnused() : void{
		$reflect = new \ReflectionClass(ItemTypeIds::class);

		$constants = $reflect->getConstants();
		unset($constants['FIRST_UNUSED_ITEM_ID']);
		self::assertNotEmpty($constants, "We should never have zero type IDs");

		$max = max($constants);
		self::assertIsInt($max, "Max type ID should always be an integer");

		self::assertSame($reflect->getConstant('FIRST_UNUSED_ITEM_ID'), $max + 1, "FIRST_UNUSED_ITEM_ID must be one higher than the highest fixed type ID");
	}

	public function testNoDuplicates() : void{
		/** @phpstan-var array<string, int> $idTable */
		$idTable = (new \ReflectionClass(ItemTypeIds::class))->getConstants();

		self::assertSameSize($idTable, array_unique($idTable), "Every ItemTypeID must be unique");
	}

	public function testVanillaItemsParity() : void{
		$reflect = new \ReflectionClass(ItemTypeIds::class);

		foreach(Utils::stringifyKeys(VanillaItems::getAll()) as $name => $item){
			if($item instanceof ItemBlock){
				continue;
			}
			$expected = $item->getTypeId();
			$actual = $reflect->getConstant($name);
			self::assertNotFalse($actual, "VanillaItems::$name() does not have an ItemTypeIds constant");
			self::assertSame($expected, $actual, "VanillaItems::$name() type ID does not match ItemTypeIds::$name");
		}
	}
}
