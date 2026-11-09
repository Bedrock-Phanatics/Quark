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

namespace quark\block;

use PHPUnit\Framework\TestCase;
use quark\crafting\CraftingManagerFromDataHelper;
use quark\data\bedrock\BedrockDataFiles;
use quark\inventory\json\CreativeGroupData;
use quark\item\VanillaItems;
use quark\world\format\io\GlobalBlockStateHandlers;
use Symfony\Component\Filesystem\Path;
use function preg_match;

final class PoplarBlocksTest extends TestCase{

	public function testBlocksRoundTripThroughBlockStates() : void{
		$serializer = GlobalBlockStateHandlers::getSerializer();
		$deserializer = GlobalBlockStateHandlers::getDeserializer();
		foreach([
			VanillaBlocks::POPLAR_LOG(), VanillaBlocks::POPLAR_WOOD(), VanillaBlocks::POPLAR_LOG()->setStripped(true),
			VanillaBlocks::POPLAR_PLANKS(), VanillaBlocks::POPLAR_SLAB(), VanillaBlocks::POPLAR_STAIRS(),
			VanillaBlocks::POPLAR_FENCE(), VanillaBlocks::POPLAR_FENCE_GATE(), VanillaBlocks::POPLAR_DOOR(),
			VanillaBlocks::POPLAR_TRAPDOOR(), VanillaBlocks::POPLAR_BUTTON(), VanillaBlocks::POPLAR_PRESSURE_PLATE(),
			VanillaBlocks::POPLAR_SIGN(), VanillaBlocks::POPLAR_WALL_SIGN(),
			VanillaBlocks::POPLAR_CEILING_CENTER_HANGING_SIGN(), VanillaBlocks::POPLAR_CEILING_EDGES_HANGING_SIGN(), VanillaBlocks::POPLAR_WALL_HANGING_SIGN(),
			VanillaBlocks::RED_POPLAR_LEAVES(), VanillaBlocks::ORANGE_POPLAR_LEAVES(), VanillaBlocks::YELLOW_POPLAR_LEAVES(),
		] as $block){
			$state = $serializer->serializeBlock($block);
			self::assertStringContainsString("poplar", $state->getName());
			self::assertSame($block->getTypeId(), $deserializer->deserializeBlock($state)->getTypeId(), $state->getName());
		}
	}

	public function testCreativeItemsAndSignsAreRegistered() : void{
		self::assertSame("Poplar Sign", VanillaItems::POPLAR_SIGN()->getName());
		self::assertSame("Poplar Hanging Sign", VanillaItems::POPLAR_HANGING_SIGN()->getName());

		$checked = 0;
		foreach(["construction", "nature", "equipment", "items"] as $category){
			$groups = CraftingManagerFromDataHelper::loadJsonArrayOfObjectsFile(
				Path::join(BedrockDataFiles::CREATIVE, $category . ".json"),
				CreativeGroupData::class
			);
			foreach($groups as $group){
				foreach($group->items as $stack){
					//saplings, shelves and boats aren't implemented for any wood yet
					if(preg_match('/poplar/', $stack->name) === 1 && preg_match('/sapling|shelf|boat/', $stack->name) === 0){
						self::assertNotNull(CraftingManagerFromDataHelper::deserializeItemStack($stack), $stack->name);
						$checked++;
					}
				}
			}
		}
		self::assertGreaterThanOrEqual(15, $checked);
	}
}
