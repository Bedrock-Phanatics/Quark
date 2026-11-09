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

use Bedwire\Nbt;
use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\Tag;
use quark\crafting\CraftingManagerFromDataHelper;
use quark\data\bedrock\BedrockDataFiles;
use quark\inventory\json\CreativeGroupData;
use quark\math\Facing;
use quark\utils\Utils;
use quark\world\format\io\GlobalBlockStateHandlers;
use Symfony\Component\Filesystem\Path;
use function base64_decode;
use function implode;
use function str_contains;
use function var_export;

/**
 * The client matches recipes against the block states the server sends for block items, so the default state of a
 * block item has to match what BDS uses.
 */
final class BlockItemDefaultsTest extends TestCase{
	/**
	 * Blocks whose default can't be expressed with our facing model, or where the vanilla default is a placeholder.
	 */
	private const KNOWN_DIFFERENCES = [
		"minecraft:bamboo" => true,
		"minecraft:ladder" => true,
		"minecraft:small_dripleaf_block" => true,
		"minecraft:potent_sulfur" => true,
	];

	public function testBlockItemDefaultsMatchVanilla() : void{
		VanillaBlocks::AIR();
		$serializer = GlobalBlockStateHandlers::getSerializer();
		$checked = 0;
		$errors = [];

		foreach(["construction", "nature", "equipment", "items"] as $category){
			$groups = CraftingManagerFromDataHelper::loadJsonArrayOfObjectsFile(
				Path::join(BedrockDataFiles::CREATIVE, $category . ".json"),
				CreativeGroupData::class
			);
			foreach($groups as $group){
				foreach($group->items as $stack){
					$name = $stack->name;
					if(!isset($stack->block_states) || isset(self::KNOWN_DIFFERENCES[$name]) || str_contains($name, "torch") || str_contains($name, "glazed_terracotta") || str_contains($name, "_head") || str_contains($name, "skull")){
						continue;
					}

					$item = CraftingManagerFromDataHelper::deserializeItemStack($stack);
					if($item === null){
						continue;
					}
					$expected = Nbt::read(Utils::assumeNotFalse(base64_decode($stack->block_states, true), "Invalid block state data"), Nbt::BEDROCK)->mustGetCompoundTag();
					$actual = $serializer->serializeBlock($item->getBlock());
					$checked++;

					/** @var array<string, Tag> $states */
					$states = $expected->getValue();
					foreach(Utils::stringifyKeys($states) as $state => $tag){
						if(!($actual->getState($state)?->equals($tag) ?? false)){
							$errors[] = "$name: $state (expected " . var_export($tag->getValue(), true) . ", got " . var_export($actual->getState($state)?->getValue(), true) . ")";
						}
					}
				}
			}
		}

		self::assertGreaterThan(400, $checked);
		self::assertSame([], $errors, "Default block item states differ from vanilla:\n" . implode("\n", $errors));
	}

	public function testSetFacingValidatesTheNewFacing() : void{
		$barrel = VanillaBlocks::BARREL();
		$barrel->setFacing(Facing::EAST);
		self::assertSame(Facing::EAST, $barrel->getFacing());

		$this->expectException(\InvalidArgumentException::class);
		$barrel->setFacing(99);
	}
}
