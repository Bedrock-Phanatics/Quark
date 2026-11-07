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
use quark\block\PistonArmCollision;
use quark\block\tile\dispenser\DispensableItem;
use quark\block\tile\dispenser\DispensableItemManager;
use quark\block\tile\dispenser\Dispenser as DispenserTile;
use quark\block\VanillaBlocks;
use quark\event\block\BlockTeleportEvent;
use quark\event\block\PistonPullBlockEvent;
use quark\event\block\PistonPushBlockEvent;
use quark\event\Event;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\inventory\Inventory;
use quark\item\VanillaItems;
use quark\player\Player;
use quark\plugin\Plugin;
use quark\plugin\PluginManager;
use quark\Server;
use quark\world\Position;
use function array_values;

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
		HandlerListManager::global()->unregisterAll();
	}

	/**
	 * @phpstan-template TEvent of Event
	 * @phpstan-param class-string<TEvent> $event
	 */
	private function cancelAll(string $event) : void{
		$plugin = self::createStub(Plugin::class);
		$plugin->method('isEnabled')->willReturn(true);
		(new PluginManager($this->createMock(Server::class), null))->registerEvent($event, static function(Event $event) : void{
			if($event instanceof PistonPushBlockEvent || $event instanceof PistonPullBlockEvent || $event instanceof BlockTeleportEvent){
				$event->cancel();
			}
		}, EventPriority::NORMAL, $plugin);
	}

	/** Piston at 0,1,0 pushing north into the stone at 0,1,-1, with a redstone block placed on its east side. */
	private function pushStone(bool $sticky) : void{
		$this->h->set(0, 1, -1, VanillaBlocks::STONE());
		$this->h->place(0, 1, 0, ($sticky ? VanillaBlocks::STICKY_PISTON() : VanillaBlocks::PISTON())->setFacing(Facing::SOUTH));
		$this->h->tick(2);
		$this->h->place(1, 1, 0, VanillaBlocks::REDSTONE());
		$this->h->tick(5);
	}

	private function isStone(int $x, int $y, int $z) : bool{
		return $this->h->get($x, $y, $z)->hasSameTypeId(VanillaBlocks::STONE());
	}

	public function testPistonPushesAndStickyPistonPulls() : void{
		$this->pushStone(true);
		self::assertSame(Piston::STATE_CONTRACT_IDLE, $this->pistonState(0, 1, 0));
		self::assertTrue($this->isStone(0, 1, -2));
		self::assertInstanceOf(PistonArmCollision::class, $this->h->get(0, 1, -1));

		$this->h->breakBlock(1, 1, 0);
		$this->h->tick(10);
		self::assertSame(Piston::STATE_RETRACT_IDLE, $this->pistonState(0, 1, 0));
		self::assertTrue($this->isStone(0, 1, -1), "the sticky piston pulled the block back");
		self::assertFalse($this->isStone(0, 1, -2));
	}

	/**
	 * @phpstan-return list<array{class-string<Event>}>
	 */
	public static function pushEventProvider() : array{
		return [[PistonPushBlockEvent::class], [BlockTeleportEvent::class]];
	}

	/**
	 * @dataProvider pushEventProvider
	 * @phpstan-param class-string<Event> $event
	 */
	public function testCancelledPushMovesNothing(string $event) : void{
		$this->cancelAll($event);
		$this->pushStone(false);
		self::assertSame(Piston::STATE_RETRACT_IDLE, $this->pistonState(0, 1, 0));
		self::assertTrue($this->isStone(0, 1, -1));
		self::assertFalse($this->isStone(0, 1, -2));
	}

	public function testCancelledPullLeavesBlockInPlace() : void{
		$this->pushStone(true);
		$this->cancelAll(PistonPullBlockEvent::class);
		$this->h->breakBlock(1, 1, 0);
		$this->h->tick(10);
		self::assertSame(Piston::STATE_RETRACT_IDLE, $this->pistonState(0, 1, 0));
		self::assertTrue($this->isStone(0, 1, -2));
		self::assertSame(VanillaBlocks::AIR()->getTypeId(), $this->h->get(0, 1, -1)->getTypeId(), "the arm is gone even though nothing was pulled");
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

	public function testDispenserFillsBucketFromLiquidSource() : void{
		DispensableItemManager::init();
		foreach(Facing::HORIZONTAL as $side){
			$pos = (new Vector3(0, 2, 0))->getSide($side);
			$this->h->set((int) $pos->x, 2, (int) $pos->z, VanillaBlocks::STONE());
		}
		$this->h->set(0, 3, 0, VanillaBlocks::STONE());
		$this->h->place(0, 1, 0, VanillaBlocks::DISPENSER()->setFacing(Facing::UP));
		$this->h->set(0, 2, 0, VanillaBlocks::WATER());
		$tile = $this->h->world->getTileAt(0, 1, 0);
		self::assertInstanceOf(DispenserTile::class, $tile);
		$tile->getInventory()->setItem(0, VanillaItems::BUCKET());
		$this->h->tick(2);

		$this->h->place(1, 1, 0, VanillaBlocks::REDSTONE());
		$this->h->tick(2);
		self::assertSame(VanillaBlocks::AIR()->getTypeId(), $this->h->get(0, 2, 0)->getTypeId());
		$contents = $tile->getInventory()->getContents();
		self::assertCount(1, $contents);
		$filled = array_values($contents)[0];
		self::assertTrue($filled->equalsExact(VanillaItems::WATER_BUCKET()), "picked up liquid becomes a filled bucket, not a liquid block item");
	}
}
