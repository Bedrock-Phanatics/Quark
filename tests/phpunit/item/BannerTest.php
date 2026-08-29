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
use quark\block\utils\BannerPatternLayer;
use quark\block\utils\BannerPatternType;
use quark\block\utils\DyeColor;
use quark\block\VanillaBlocks;
use function assert;

final class BannerTest extends TestCase{

	public function testBannerPatternSaveRestore() : void{
		$item = VanillaBlocks::BANNER()->asItem();
		assert($item instanceof Banner);
		$item->setPatterns([
			new BannerPatternLayer(BannerPatternType::FLOWER, DyeColor::RED)
		]);
		$data = $item->nbtSerialize();

		$item2 = Item::nbtDeserialize($data);
		self::assertTrue($item->equalsExact($item2));
		self::assertInstanceOf(Banner::class, $item2);
		$patterns = $item2->getPatterns();
		self::assertCount(1, $patterns);
		self::assertTrue(BannerPatternType::FLOWER === $patterns[0]->getType());
	}
}
