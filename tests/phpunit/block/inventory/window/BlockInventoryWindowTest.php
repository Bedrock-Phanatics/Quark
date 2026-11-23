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

namespace quark\block\inventory\window;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use quark\block\VanillaBlocks;
use quark\command\TestCommandSender;
use quark\math\Vector3;
use quark\player\Player;
use quark\world\Position;
use quark\world\redstone\RedstoneHarness;

final class BlockInventoryWindowTest extends TestCase{
	private RedstoneHarness $h;
	private Player&MockObject $viewer;
	private Vector3 $eyes;

	protected function setUp() : void{
		$this->h = new RedstoneHarness(1);
		$this->h->set(0, 1, 0, VanillaBlocks::CRAFTING_TABLE());
		$this->eyes = new Vector3(2.5, 2.6, 0.5);
		$this->viewer = TestCommandSender::closedPlayer($this->createMock(Player::class));
		$this->viewer->method("getWorld")->willReturnCallback(fn() => $this->h->world);
		$this->viewer->method("getEyePos")->willReturnCallback(fn() => $this->eyes);
	}

	private function openWindow() : CraftingTableInventoryWindow{
		$window = new CraftingTableInventoryWindow($this->viewer, new Position(0, 1, 0, $this->h->world));
		$window->onOpen();
		return $window;
	}

	public function testWindowIsUsableWhileTheBlockIsInReach() : void{
		self::assertTrue($this->openWindow()->isUsable());
	}

	public function testWindowIsUnusableOnceTheBlockIsBroken() : void{
		$window = $this->openWindow();
		$this->h->set(0, 1, 0, VanillaBlocks::AIR());
		self::assertFalse($window->isUsable());
	}

	public function testWindowIsUnusableOutOfReach() : void{
		$window = $this->openWindow();
		$this->eyes = new Vector3(20.5, 2.6, 0.5);
		self::assertFalse($window->isUsable());
	}

	public function testWindowsWithoutAWorldAreNotTiedToABlock() : void{
		$window = new CraftingTableInventoryWindow($this->viewer, new Position(0, 1, 0, null));
		$window->onOpen();
		self::assertTrue($window->isUsable());
	}
}
