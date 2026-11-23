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

namespace quark\network\mcpe\handler;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequest;
use quark\block\VanillaBlocks;
use quark\command\TestCommandSender;
use quark\crafting\CraftingGrid;
use quark\crafting\CraftingManager;
use quark\crafting\ExactRecipeIngredient;
use quark\crafting\ShapelessRecipe;
use quark\crafting\ShapelessRecipeType;
use quark\network\mcpe\cache\CraftingDataCache;
use quark\network\mcpe\InventoryManager;
use quark\player\Player;
use quark\Server;

final class ItemStackRequestExecutorTest extends TestCase{

	private function begin(ShapelessRecipeType $type) : void{
		$manager = new CraftingManager();
		$manager->registerShapelessRecipe(new ShapelessRecipe(
			[new ExactRecipeIngredient(VanillaBlocks::COBBLESTONE()->asItem())],
			[VanillaBlocks::COBBLESTONE_STAIRS()->asItem()],
			$type
		));
		$server = $this->createMock(Server::class);
		$server->method("getCraftingManager")->willReturn($manager);
		$player = TestCommandSender::closedPlayer($this->createMock(Player::class));
		$player->method("getServer")->willReturn($server);
		$player->method("getCraftingGrid")->willReturn(new CraftingGrid(CraftingGrid::SIZE_SMALL));

		$executor = new ItemStackRequestExecutor($player, $this->createMock(InventoryManager::class), new ItemStackRequest(1, [], [], 0));
		(new \ReflectionMethod($executor, "beginCrafting"))->invoke($executor, CraftingDataCache::RECIPE_ID_OFFSET, 1);
	}

	public function testCraftingGridRecipesCanBeCrafted() : void{
		$this->begin(ShapelessRecipeType::CRAFTING);
		$this->addToAssertionCount(1);
	}

	public function testStonecutterRecipesCannotBeCraftedInACraftingGrid() : void{
		$this->expectException(ItemStackRequestProcessException::class);
		$this->begin(ShapelessRecipeType::STONECUTTER);
	}
}
