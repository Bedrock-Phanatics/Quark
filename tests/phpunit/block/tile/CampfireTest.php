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

namespace quark\block\tile;

use PHPUnit\Framework\TestCase;
use quark\block\Campfire as BlockCampfire;
use quark\block\VanillaBlocks;
use quark\crafting\CraftingManagerFromDataHelper;
use quark\data\bedrock\BedrockDataFiles;
use quark\item\VanillaItems;
use quark\Server;
use quark\world\redstone\RedstoneHarness;

final class CampfireTest extends TestCase{
	private RedstoneHarness $harness;

	protected function setUp() : void{
		$this->harness = new RedstoneHarness();
		$server = $this->harness->world->getServer();
		(new \ReflectionProperty(Server::class, "craftingManager"))->setValue($server, CraftingManagerFromDataHelper::make(BedrockDataFiles::RECIPES));
	}

	private function placeCampfire(int $x = 2, int $z = 2) : BlockCampfire{
		$this->harness->place($x, 64, $z, VanillaBlocks::CAMPFIRE());
		$block = $this->harness->get($x, 64, $z);
		self::assertInstanceOf(BlockCampfire::class, $block);
		return $block;
	}

	private function tile(BlockCampfire $block) : Campfire{
		$tile = $this->harness->world->getTile($block->getPosition());
		self::assertInstanceOf(Campfire::class, $tile);
		return $tile;
	}

	public function testInventoryBelongsToTheTile() : void{
		$block = $this->placeCampfire();
		$tile = $this->tile($block);
		self::assertSame($tile->getInventory(), $block->getInventory());

		$beef = VanillaItems::RAW_BEEF();
		self::assertTrue($block->onInteract($beef, 1, $block->getPosition()->asVector3()));
		self::assertTrue($beef->isNull(), "the ingredient is taken from the player");
		self::assertTrue($tile->getInventory()->getItem(0)->equals(VanillaItems::RAW_BEEF()));
	}

	public function testReplacedTileInventoryIsNotReachable() : void{
		$block = $this->placeCampfire();
		$oldInventory = $this->tile($block)->getInventory();
		$oldInventory->setItem(0, VanillaItems::RAW_BEEF());

		$this->harness->set(2, 64, 2, VanillaBlocks::STONE());
		self::assertNull($block->getInventory(), "no tile is left to read from");

		$next = $this->placeCampfire();
		self::assertNotSame($oldInventory, $next->getInventory());
		self::assertTrue($next->getInventory()?->isSlotEmpty(0));
	}

	public function testMissingTileIsHandled() : void{
		$block = $this->placeCampfire();
		$this->harness->world->removeTile($this->tile($block));

		self::assertNull($block->getInventory());
		self::assertSame(0, $block->getCookingTime(0));
		$block->setCookingTime(0, 10);
		$block->onScheduledUpdate();

		$beef = VanillaItems::RAW_BEEF();
		self::assertFalse($block->onInteract($beef, 1, $block->getPosition()->asVector3()));
		self::assertSame(1, $beef->getCount(), "the item isn't lost to a detached inventory");
	}

	public function testCookingProgressSurvivesSaving() : void{
		$tile = new Campfire();
		$tile->getInventory()->setItem(1, VanillaItems::RAW_BEEF());
		$tile->setCookingTime(1, 250);

		$copy = TileFactory::getInstance()->createFromData($tile->saveNBT());
		self::assertInstanceOf(Campfire::class, $copy);
		self::assertTrue($copy->getInventory()->getItem(1)->equals(VanillaItems::RAW_BEEF()));
		self::assertSame(250, $copy->getCookingTime(1));
		self::assertSame(0, $copy->getCookingTime(0));
	}

	public function testCookingTimeWithoutAnItemIsIgnoredOnLoad() : void{
		$tile = new Campfire();
		$tile->getInventory()->setItem(1, VanillaItems::RAW_BEEF());
		$tile->setCookingTime(1, 250);
		$nbt = $tile->saveNBT();
		$nbt->setInt("ItemTime3", 400);

		$copy = TileFactory::getInstance()->createFromData($nbt);
		self::assertInstanceOf(Campfire::class, $copy);
		self::assertSame(250, $copy->getCookingTime(1));
		self::assertSame(0, $copy->getCookingTime(2), "an empty slot has no progress to inherit");
	}

	public function testCookingTimeResetsWhenTheItemChanges() : void{
		$tile = new Campfire();
		$tile->getInventory()->setItem(0, VanillaItems::RAW_BEEF());
		$tile->setCookingTime(0, 300);

		$tile->getInventory()->setItem(0, VanillaItems::RAW_BEEF());
		self::assertSame(300, $tile->getCookingTime(0), "an identical item keeps its progress");

		$tile->getInventory()->setItem(0, VanillaItems::RAW_PORKCHOP());
		self::assertSame(0, $tile->getCookingTime(0));

		$tile->setCookingTime(0, 300);
		$tile->getInventory()->clear(0);
		self::assertSame(0, $tile->getCookingTime(0));
	}

	public function testLitCampfireCooksItems() : void{
		$this->harness->set(2, 64, 2, VanillaBlocks::CAMPFIRE()->setLit(true));
		$block = $this->harness->get(2, 64, 2);
		self::assertInstanceOf(BlockCampfire::class, $block);
		$tile = $this->tile($block);
		$tile->getInventory()->setItem(0, VanillaItems::RAW_BEEF());

		$block->onScheduledUpdate();
		self::assertSame(10, $tile->getCookingTime(0));
		self::assertFalse($tile->getInventory()->isSlotEmpty(0));

		$block->setCookingTime(0, 590);
		$block->onScheduledUpdate();
		self::assertTrue($tile->getInventory()->isSlotEmpty(0), "the finished item is taken out");
		self::assertSame(0, $tile->getCookingTime(0));
	}

	public function testUnlitCampfireDoesNotCook() : void{
		$block = $this->placeCampfire();
		self::assertFalse($block->isLit());
		$tile = $this->tile($block);
		$tile->getInventory()->setItem(0, VanillaItems::RAW_BEEF());

		$block->onScheduledUpdate();
		self::assertSame(0, $tile->getCookingTime(0));
	}
}
