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
use quark\block\VanillaBlocks;

class LegacyStringToItemParserTest extends TestCase{

	/**
	 * @return mixed[][]
	 * @phpstan-return list<array{string,Item}>
	 */
	public static function itemFromStringProvider() : array{
		return [
			["dye:4", VanillaItems::LAPIS_LAZULI()],
			["351", VanillaItems::INK_SAC()],
			["351:4", VanillaItems::LAPIS_LAZULI()],
			["stone:3", VanillaBlocks::DIORITE()->asItem()],
			["minecraft:string", VanillaItems::STRING()],
			["diamond_pickaxe", VanillaItems::DIAMOND_PICKAXE()]
		];
	}

	/**
	 * @dataProvider itemFromStringProvider
	 */
	public function testFromStringSingle(string $string, Item $expected) : void{
		$item = LegacyStringToItemParser::getInstance()->parse($string);

		self::assertTrue($item->equals($expected));
	}
}
