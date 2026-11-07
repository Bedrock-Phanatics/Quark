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

namespace quark\world\redstone;

use PHPUnit\Framework\TestCase;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use quark\block\Piston;
use quark\block\tile\dispenser\DispensableItem;
use quark\block\tile\dispenser\DispensableItemManager;
use quark\block\tile\dispenser\Dispenser as DispenserTile;
use quark\block\VanillaBlocks;
use quark\inventory\Inventory;
use quark\item\VanillaItems;
use quark\player\Player;
use quark\world\Position;

final class RedstoneMachineTest extends TestCase{

	private RedstoneHarness $h;

	protected function setUp() : void{
		$this->h = new RedstoneHarness();
		for($x = -4; $x <= 4; ++$x){
			for($z = -4; $z <= 4; ++$z){
				$this->h->set($x, 0, $z, VanillaBlocks::STONE());
			}
		}
		$this->h->tick();
	}

	protected function tearDown() : void{
		DispensableItemManager::init();
	}

	private function pistonState(int $x, int $y, int $z) : int{
		$piston = $this->h->get($x, $y, $z);
		self::assertInstanceOf(Piston::class, $piston);
		return $piston->getState();
	}

	public function testPistonOverBudgetExtendsOnALaterTick() : void{
		RedstoneConfig::configure(65536, 262144, 8192, true, true, 1, 4096);
		//piston facing is stored the bedrock way, opposite to the push direction for horizontals
		$this->h->place(0, 1, -1, VanillaBlocks::PISTON()->setFacing(Facing::SOUTH));
		$this->h->place(0, 1, 1, VanillaBlocks::PISTON()->setFacing(Facing::NORTH));
		$this->h->tick(2);
		$this->h->place(0, 1, 0, VanillaBlocks::REDSTONE());
		$this->h->tick(5);
		self::assertSame(Piston::STATE_CONTRACT_IDLE, $this->pistonState(0, 1, -1));
		self::assertSame(Piston::STATE_CONTRACT_IDLE, $this->pistonState(0, 1, 1), "the second piston retried instead of losing its input");

		$this->h->breakBlock(0, 1, 0);
		$this->h->tick(10);
		self::assertSame(Piston::STATE_RETRACT_IDLE, $this->pistonState(0, 1, -1));
		self::assertSame(Piston::STATE_RETRACT_IDLE, $this->pistonState(0, 1, 1));
	}

	public function testDispenserOverBudgetFiresOnALaterTick() : void{
		RedstoneConfig::configure(65536, 262144, 8192, true, true, 1024, 1);
		$counter = new class implements DispensableItem{
			public int $fired = 0;

			public function dispense(Position $pos, Inventory $inventory, int $slot, Vector3 $side_pos, int $facing, ?Player $player = null) : bool{
				++$this->fired;
				$item = $inventory->getItem($slot);
				$item->pop();
				$inventory->setItem($slot, $item);
				return true;
			}
		};
		DispensableItemManager::registerFallback($counter);
		foreach([-1, 1] as $z){
			$this->h->place(0, 1, $z, VanillaBlocks::DISPENSER()->setFacing(Facing::UP));
			$tile = $this->h->world->getTileAt(0, 1, $z);
			self::assertInstanceOf(DispenserTile::class, $tile);
			$tile->getInventory()->setItem(0, VanillaItems::STICK()->setCount(4));
		}
		$this->h->tick(2);
		$this->h->place(0, 1, 0, VanillaBlocks::REDSTONE());
		$this->h->tick(5);
		self::assertSame(2, $counter->fired, "both dispensers fire once");

		$this->h->tick(10);
		self::assertSame(2, $counter->fired, "a held signal doesn't refire");
	}
}
