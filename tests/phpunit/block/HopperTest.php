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
use pocketmine\nbt\tag\CompoundTag;
use quark\block\inventory\window\BrewingStandInventoryWindow;
use quark\block\inventory\window\FurnaceInventoryWindow;
use quark\block\tile\Chest as TileChest;
use quark\block\tile\Hopper as TileHopper;
use quark\block\utils\HopperConfig;
use quark\crafting\CraftingManager;
use quark\crafting\CraftingManagerFromDataHelper;
use quark\data\bedrock\BedrockDataFiles;
use quark\entity\object\ItemEntity;
use quark\event\block\HopperPickupItemEvent;
use quark\event\block\HopperTransferEvent;
use quark\event\Event;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\inventory\CallbackInventoryListener;
use quark\inventory\Inventory;
use quark\inventory\SimpleInventory;
use quark\item\Item;
use quark\item\VanillaItems;
use quark\math\Facing;
use quark\math\Vector3;
use quark\plugin\Plugin;
use quark\plugin\PluginManager;
use quark\Server;
use quark\world\redstone\RedstoneHarness;
use quark\world\World;
use function array_fill;
use function array_map;
use function array_sum;
use function count;
use function intdiv;
use function ksort;
use function max;
use function min;
use function mt_rand;
use function mt_srand;

final class HopperTest extends TestCase{

	private static ?CraftingManager $craftingManager = null;

	private RedstoneHarness $h;
	private World $world;

	protected function setUp() : void{
		$this->h = new RedstoneHarness(1);
		$this->world = $this->h->world;
		HopperConfig::configure(true, 8, 1, 0);
		(new \ReflectionProperty(Server::class, "craftingManager"))->setValue(
			$this->world->getServer(),
			self::$craftingManager ??= CraftingManagerFromDataHelper::make(BedrockDataFiles::RECIPES)
		);
	}

	protected function tearDown() : void{
		HopperConfig::configure(true, 8, 1, 512);
		HandlerListManager::global()->unregisterAll();
	}

	private function hopper(int $x, int $y, int $z, int $facing = Facing::DOWN) : TileHopper{
		$this->h->set($x, $y, $z, VanillaBlocks::HOPPER()->setFacing($facing));
		$tile = $this->world->getTileAt($x, $y, $z);
		self::assertInstanceOf(TileHopper::class, $tile);
		return $tile;
	}

	private function inventory(int $x, int $y, int $z) : Inventory{
		$block = $this->world->getBlockAt($x, $y, $z);
		self::assertInstanceOf(utils\Container::class, $block);
		return $block->getInventory() ?? self::fail("No inventory at $x $y $z");
	}

	private function chest(int $x, int $y, int $z) : Inventory{
		$this->h->set($x, $y, $z, VanillaBlocks::CHEST());
		return $this->inventory($x, $y, $z);
	}

	private static function key(Item $item) : string{
		return (string) (clone $item)->setCount(1);
	}

	/**
	 * Counts items by identity across inventories and the dropped items in the world.
	 *
	 * @param Inventory[] $inventories
	 * @return array<string, int>
	 */
	private function census(array $inventories) : array{
		$counts = [];
		foreach($inventories as $inventory){
			foreach($inventory->getContents() as $item){
				$counts[self::key($item)] = ($counts[self::key($item)] ?? 0) + $item->getCount();
			}
		}
		foreach($this->world->getEntities() as $entity){
			if($entity instanceof ItemEntity && !$entity->isFlaggedForDespawn() && !$entity->isClosed()){
				$item = $entity->getItem();
				$counts[self::key($item)] = ($counts[self::key($item)] ?? 0) + $item->getCount();
			}
		}
		ksort($counts);
		return $counts;
	}

	/**
	 * @phpstan-param class-string<Event> $event
	 * @phpstan-param \Closure(Event) : void $handler
	 */
	private function listen(string $event, \Closure $handler) : void{
		$plugin = self::createStub(Plugin::class);
		$plugin->method('isEnabled')->willReturn(true);
		(new PluginManager($this->createMock(Server::class), null))->registerEvent($event, $handler, EventPriority::NORMAL, $plugin);
	}

	public function testPushesOneItemPerCooldownIntoChest() : void{
		$hopper = $this->hopper(0, 64, 0, Facing::EAST);
		$chest = $this->chest(1, 64, 0);
		$hopper->getInventory()->setItem(0, VanillaItems::DIAMOND()->setCount(10));

		$this->h->tick();
		self::assertSame(1, $chest->getItem(0)->getCount());
		self::assertSame(9, $hopper->getInventory()->getItem(0)->getCount());

		$this->h->tick(7);
		self::assertSame(1, $chest->getItem(0)->getCount(), "the cooldown holds the next transfer back");
		$this->h->tick();
		self::assertSame(2, $chest->getItem(0)->getCount());

		$this->h->tick(8 * 20);
		self::assertSame(10, $chest->getItem(0)->getCount());
		self::assertTrue($hopper->getInventory()->isSlotEmpty(0));
	}

	public function testPullsFromChestAboveIntoEmptyAndPartialSlots() : void{
		$chest = $this->chest(0, 65, 0);
		$hopper = $this->hopper(0, 64, 0, Facing::EAST);
		$chest->setItem(3, VanillaItems::STICK()->setCount(2));
		$chest->setItem(5, VanillaItems::DIAMOND()->setCount(3));
		$hopper->getInventory()->setItem(1, VanillaItems::STICK()->setCount(63));

		$this->h->tick(9);
		self::assertSame(64, $hopper->getInventory()->getItem(1)->getCount(), "tops up the partial stack first");
		self::assertSame(1, $hopper->getInventory()->getItem(0)->getCount(), "then uses the first free slot");
		self::assertTrue($chest->isSlotEmpty(3));
		self::assertSame(3, $chest->getItem(5)->getCount());
		$this->h->tick(8);
		self::assertSame(2, $chest->getItem(5)->getCount());
	}

	public function testPullsAndPushesInTheSameUpdate() : void{
		$above = $this->chest(0, 65, 0);
		$hopper = $this->hopper(0, 64, 0, Facing::EAST);
		$target = $this->chest(1, 64, 0);
		$above->setItem(0, VanillaItems::DIAMOND()->setCount(4));
		$hopper->getInventory()->setItem(0, VanillaItems::STICK());

		$this->h->tick();
		self::assertSame(VanillaItems::STICK()->getTypeId(), $target->getItem(0)->getTypeId());
		self::assertSame(3, $above->getItem(0)->getCount());
		self::assertSame(1, $hopper->getInventory()->getItem(0)->getCount());
	}

	public function testChainMovesOneHopPerCooldown() : void{
		$a = $this->hopper(0, 64, 0, Facing::EAST);
		$b = $this->hopper(1, 64, 0, Facing::EAST);
		$c = $this->hopper(2, 64, 0, Facing::EAST);
		$end = $this->chest(3, 64, 0);
		$this->h->tick(20);
		$a->getInventory()->setItem(0, VanillaItems::DIAMOND());

		$this->h->tick();
		self::assertFalse($b->getInventory()->isSlotEmpty(0));
		$this->h->tick(7);
		self::assertTrue($c->getInventory()->isSlotEmpty(0), "an item entering an empty hopper waits a full cooldown");
		$this->h->tick();
		self::assertFalse($c->getInventory()->isSlotEmpty(0));
		$this->h->tick(8);
		self::assertSame(1, $end->getItem(0)->getCount());
	}

	public function testCircularHoppersKeepEveryItem() : void{
		$ring = [[0, 0, Facing::EAST], [1, 0, Facing::SOUTH], [1, 1, Facing::WEST], [0, 1, Facing::NORTH]];
		$inventories = [];
		foreach($ring as [$x, $z, $facing]){
			$inventories[] = $this->hopper($x, 64, $z, $facing)->getInventory();
		}
		$inventories[0]->setContents([VanillaItems::DIAMOND()->setCount(20), VanillaItems::STICK()->setCount(5), VanillaItems::DIAMOND_SWORD()]);
		$inventories[2]->setItem(4, VanillaItems::APPLE()->setCount(64));
		$before = $this->census($inventories);

		for($i = 0; $i < 100; ++$i){
			$this->h->tick(7);
			self::assertSame($before, $this->census($inventories));
		}
		foreach($inventories as $inventory){
			self::assertNotSame([], $inventory->getContents(), "items keep circulating");
		}
	}

	public function testDoubleChestFillsBothHalves() : void{
		$this->h->set(1, 64, 0, VanillaBlocks::CHEST());
		$this->h->set(1, 64, 1, VanillaBlocks::CHEST());
		$left = $this->world->getTileAt(1, 64, 0);
		$right = $this->world->getTileAt(1, 64, 1);
		self::assertInstanceOf(TileChest::class, $left);
		self::assertInstanceOf(TileChest::class, $right);
		self::assertTrue($left->pairWith($right));
		for($slot = 0; $slot < 27; ++$slot){
			$left->getInventory()->setItem($slot, VanillaItems::STICK()->setCount(64));
		}
		$hopper = $this->hopper(0, 64, 0, Facing::EAST);
		$hopper->getInventory()->setItem(0, VanillaItems::DIAMOND()->setCount(2));

		$this->h->tick(9);
		self::assertSame(2, $right->getInventory()->getItem(0)->getCount());
	}

	public function testFullTargetSleepsUntilItHasRoom() : void{
		$hopper = $this->hopper(0, 64, 0, Facing::EAST);
		$this->h->set(1, 64, 0, VanillaBlocks::HOPPER());
		$target = $this->inventory(1, 64, 0);
		for($slot = 0; $slot < 5; ++$slot){
			$target->setItem($slot, VanillaItems::STICK()->setCount(64));
		}
		//the target points down at nothing, so it stays full
		$hopper->getInventory()->setItem(0, VanillaItems::DIAMOND());
		$this->h->tick(100);
		self::assertSame(1, $hopper->getInventory()->getItem(0)->getCount());

		$target->clear(3);
		$this->h->tick(2);
		self::assertTrue($hopper->getInventory()->isSlotEmpty(0), "the target's change wakes the hopper");
		self::assertSame(VanillaItems::DIAMOND()->getTypeId(), $target->getItem(3)->getTypeId());
	}

	public function testItemsOnlyStackWithIdenticalItems() : void{
		$hopper = $this->hopper(0, 64, 0, Facing::EAST);
		$this->h->set(1, 64, 0, VanillaBlocks::HOPPER());
		$target = $this->inventory(1, 64, 0);
		$named = VanillaItems::DIAMOND()->setCustomName("Shiny");
		$target->setItem(0, VanillaItems::DIAMOND()->setCount(5));
		$target->setItem(1, VanillaItems::DIAMOND_SWORD());
		$target->setItem(2, VanillaItems::STICK()->setCount(64));
		$target->setItem(3, (clone $named)->setCount(10));
		$hopper->getInventory()->setItem(0, VanillaItems::DIAMOND_SWORD());
		$hopper->getInventory()->setItem(1, (clone $named)->setCount(2));

		$this->h->tick();
		self::assertSame(VanillaItems::DIAMOND_SWORD()->getTypeId(), $target->getItem(4)->getTypeId(), "unstackable items take an empty slot");
		$this->h->tick(8);
		self::assertSame(11, $target->getItem(3)->getCount(), "tagged items stack only onto the same tags");
		self::assertSame(5, $target->getItem(0)->getCount());
	}

	public function testFurnaceSlotsFollowTheSide() : void{
		$this->h->set(0, 64, 0, VanillaBlocks::FURNACE());
		$furnace = $this->inventory(0, 64, 0);
		$top = $this->hopper(0, 65, 0, Facing::DOWN);
		$side = $this->hopper(-1, 64, 0, Facing::EAST);
		$below = $this->hopper(0, 63, 0, Facing::WEST);
		$top->getInventory()->setItem(0, VanillaItems::RAW_IRON()->setCount(2));
		$side->getInventory()->setItem(0, VanillaItems::DIAMOND());
		$side->getInventory()->setItem(1, VanillaItems::COAL());
		$furnace->setItem(FurnaceInventoryWindow::SLOT_RESULT, VanillaItems::IRON_INGOT()->setCount(3));

		$this->h->tick();
		self::assertSame(VanillaItems::RAW_IRON()->getTypeId(), $furnace->getItem(FurnaceInventoryWindow::SLOT_INPUT)->getTypeId());
		self::assertSame(VanillaItems::COAL()->getTypeId(), $furnace->getItem(FurnaceInventoryWindow::SLOT_FUEL)->getTypeId(), "only fuel enters from the side");
		self::assertSame(1, $side->getInventory()->getItem(0)->getCount());
		self::assertSame(VanillaItems::IRON_INGOT()->getTypeId(), $below->getInventory()->getItem(0)->getTypeId(), "the hopper below takes results");
		self::assertTrue($below->getInventory()->isSlotEmpty(1));
	}

	public function testFurnaceGivesUpEmptyBucketButNotFuel() : void{
		$this->h->set(0, 64, 0, VanillaBlocks::FURNACE());
		$furnace = $this->inventory(0, 64, 0);
		$below = $this->hopper(0, 63, 0, Facing::WEST);
		$furnace->setItem(FurnaceInventoryWindow::SLOT_FUEL, VanillaItems::COAL());
		$furnace->setItem(FurnaceInventoryWindow::SLOT_INPUT, VanillaItems::RAW_IRON());
		$this->h->tick(20);
		self::assertSame([], $below->getInventory()->getContents());

		$furnace->setItem(FurnaceInventoryWindow::SLOT_FUEL, VanillaItems::BUCKET());
		$this->h->tick(2);
		self::assertSame(VanillaItems::BUCKET()->getTypeId(), $below->getInventory()->getItem(0)->getTypeId());
	}

	public function testBrewingStandBottlesFuelAndExtraction() : void{
		$this->h->set(0, 64, 0, VanillaBlocks::BREWING_STAND());
		$stand = $this->inventory(0, 64, 0);
		$side = $this->hopper(-1, 64, 0, Facing::EAST);
		$side->getInventory()->setContents([VanillaItems::STICK(), VanillaItems::POTION(), VanillaItems::POTION(), VanillaItems::BLAZE_POWDER()->setCount(2)]);

		$this->h->tick(8 * 4 + 1);
		self::assertSame(VanillaItems::STICK()->getTypeId(), $side->getInventory()->getItem(0)->getTypeId(), "a stick fits nowhere");
		self::assertSame(VanillaItems::BLAZE_POWDER()->getTypeId(), $stand->getItem(BrewingStandInventoryWindow::SLOT_FUEL)->getTypeId());
		self::assertFalse($stand->isSlotEmpty(BrewingStandInventoryWindow::SLOT_BOTTLE_LEFT));
		self::assertFalse($stand->isSlotEmpty(BrewingStandInventoryWindow::SLOT_BOTTLE_MIDDLE));
		self::assertTrue($stand->isSlotEmpty(BrewingStandInventoryWindow::SLOT_INGREDIENT));

		$below = $this->hopper(0, 63, 0, Facing::WEST);
		$this->h->tick(8 * 3 + 1);
		self::assertCount(2, $below->getInventory()->getContents(), "bottles come out the bottom, fuel stays");
		self::assertFalse($stand->isSlotEmpty(BrewingStandInventoryWindow::SLOT_FUEL));
	}

	public function testRedstoneLocksWithoutResettingCooldown() : void{
		$hopper = $this->hopper(0, 64, 0, Facing::EAST);
		$chest = $this->chest(1, 64, 0);
		$hopper->getInventory()->setItem(0, VanillaItems::DIAMOND()->setCount(5));
		$this->h->tick();
		self::assertSame(1, $chest->getItem(0)->getCount());

		$this->h->place(-1, 64, 0, VanillaBlocks::REDSTONE());
		$this->h->tick(2);
		$block = $this->world->getBlockAt(0, 64, 0);
		self::assertTrue($block instanceof Hopper && $block->isPowered());
		$this->h->tick(40);
		self::assertSame(1, $chest->getItem(0)->getCount(), "a powered hopper is locked");

		$this->h->breakBlock(-1, 64, 0);
		$this->h->tick(2);
		self::assertSame(2, $chest->getItem(0)->getCount(), "unlocking resumes at once since the cooldown ran out meanwhile");
	}

	public function testHigherItemsPerTransfer() : void{
		HopperConfig::configure(true, 8, 16, 0);
		$hopper = $this->hopper(0, 64, 0, Facing::EAST);
		$this->h->set(1, 64, 0, VanillaBlocks::HOPPER());
		$target = $this->inventory(1, 64, 0);
		$target->setItem(0, VanillaItems::DIAMOND()->setCount(60));
		$target->setItem(1, VanillaItems::STICK()->setCount(64));
		$target->setItem(2, VanillaItems::STICK()->setCount(64));
		$target->setItem(3, VanillaItems::STICK()->setCount(64));
		$target->setItem(4, VanillaItems::DIAMOND()->setCount(58));
		$hopper->getInventory()->setItem(0, VanillaItems::DIAMOND()->setCount(40));

		$this->h->tick();
		self::assertSame(64, $target->getItem(0)->getCount());
		self::assertSame(64, $target->getItem(4)->getCount());
		self::assertSame(30, $hopper->getInventory()->getItem(0)->getCount(), "only what fits leaves");
	}

	public function testPicksUpDroppedItemsAndLeavesTheRest() : void{
		$hopper = $this->hopper(0, 64, 0, Facing::DOWN);
		for($slot = 0; $slot < 4; ++$slot){
			$hopper->getInventory()->setItem($slot, VanillaItems::STICK()->setCount(64));
		}
		$hopper->getInventory()->setItem(4, VanillaItems::DIAMOND()->setCount(60));
		$entity = $this->world->dropItem(new Vector3(0.5, 65.2, 0.5), VanillaItems::DIAMOND()->setCount(10), Vector3::zero());
		self::assertNotNull($entity);
		$before = $this->census([$hopper->getInventory()]);

		$this->h->tick(2);
		self::assertSame(64, $hopper->getInventory()->getItem(4)->getCount());
		self::assertFalse($entity->isFlaggedForDespawn());
		self::assertSame(6, $entity->getItem()->getCount(), "the remainder stays in the same entity");
		self::assertSame($before, $this->census([$hopper->getInventory()]));

		$hopper->getInventory()->clear(0);
		$before = $this->census([$hopper->getInventory()]);
		$this->h->tick(8);
		self::assertTrue($entity->isFlaggedForDespawn());
		self::assertSame(6, $hopper->getInventory()->getItem(0)->getCount());
		self::assertSame($before, $this->census([$hopper->getInventory()]));
	}

	public function testCancelledEventsMoveNothing() : void{
		$this->listen(HopperTransferEvent::class, static fn(Event $e) => $e instanceof HopperTransferEvent ? $e->cancel() : null);
		$this->listen(HopperPickupItemEvent::class, static fn(Event $e) => $e instanceof HopperPickupItemEvent ? $e->cancel() : null);
		$hopper = $this->hopper(0, 64, 0, Facing::EAST);
		$chest = $this->chest(1, 64, 0);
		$hopper->getInventory()->setItem(0, VanillaItems::DIAMOND());
		$entity = $this->world->dropItem(new Vector3(0.5, 65.2, 0.5), VanillaItems::STICK(), Vector3::zero());
		self::assertNotNull($entity);

		$this->h->tick(50);
		self::assertSame([], $chest->getContents());
		self::assertSame(1, $hopper->getInventory()->getItem(0)->getCount());
		self::assertFalse($entity->isFlaggedForDespawn());
	}

	/**
	 * Plugin code runs between planning a transfer and making it. Every mutation here only moves items around, so the
	 * total over all three inventories and the world must never change.
	 */
	public function testListenerMutationsNeverDuplicateOrLoseItems() : void{
		$source = $this->hopper(0, 64, 0, Facing::EAST)->getInventory();
		$this->h->set(1, 64, 0, VanillaBlocks::HOPPER()->setFacing(Facing::WEST));
		$target = $this->inventory(1, 64, 0);
		$stash = new SimpleInventory(5);
		$stash->setContents(array_fill(0, 5, VanillaBlocks::STONE()->asItem()->setCount(64)));
		$swap = static function(Inventory $a, Inventory $b) : void{
			$contents = $a->getContents();
			$a->setContents($b->getContents());
			$b->setContents($contents);
		};
		mt_srand(7);
		//the swaps fire listeners themselves, and a nested swap would work from stale contents
		$busy = new \ArrayObject([false]);
		$handler = static function() use ($source, $target, $stash, $swap, $busy) : void{
			if($busy[0] === true){
				return;
			}
			$busy[0] = true;
			try{
				match(mt_rand(0, 5)){
					0 => $source->swap(0, mt_rand(1, 4)),
					1 => $swap($target, $stash),
					2 => $swap($source, $stash),
					3 => $swap($source, $target),
					default => null
				};
			}finally{
				$busy[0] = false;
			}
		};
		$this->listen(HopperTransferEvent::class, $handler);
		$target->getListeners()->add(CallbackInventoryListener::onAnyChange(static function() use ($handler) : void{
			if(mt_rand(0, 3) === 0){
				$handler();
			}
		}));
		$source->setContents([VanillaItems::DIAMOND()->setCount(30), VanillaItems::APPLE()->setCount(20), VanillaItems::DIAMOND_SWORD()]);
		$inventories = [$source, $target, $stash];
		$before = $this->census($inventories);
		for($i = 0; $i < 400; ++$i){
			$this->h->tick();
			self::assertSame($before, $this->census($inventories), "tick $i");
		}
	}

	public function testExceptionInListenerKeepsItems() : void{
		$hopper = $this->hopper(0, 64, 0, Facing::EAST);
		$chest = $this->chest(1, 64, 0);
		$hopper->getInventory()->setItem(0, VanillaItems::DIAMOND()->setCount(3));
		$throws = true;
		$chest->getListeners()->add(CallbackInventoryListener::onAnyChange(static function() use (&$throws) : void{
			if($throws){
				$throws = false;
				throw new \RuntimeException("listener failure");
			}
		}));
		$inventories = [$hopper->getInventory(), $chest];
		$before = $this->census($inventories);
		try{
			$this->h->tick();
			self::fail("the exception should reach the caller");
		}catch(\RuntimeException $e){
			self::assertSame("listener failure", $e->getMessage());
		}
		self::assertSame($before, $this->census($inventories));
		$this->h->tick(300);
		self::assertSame(3, $chest->getItem(0)->getCount(), "the hopper recovers through its recheck");
	}

	public function testTargetDestroyedByListenerKeepsItems() : void{
		HopperConfig::configure(true, 8, 4, 0);
		$hopper = $this->hopper(0, 64, 0, Facing::EAST);
		$chest = $this->chest(1, 64, 0);
		$chest->setItem(0, VanillaItems::STICK()->setCount(10));
		$hopper->getInventory()->setItem(0, VanillaItems::DIAMOND()->setCount(9));
		$this->listen(HopperTransferEvent::class, function() : void{
			$this->h->breakBlock(1, 64, 0);
		});
		$inventories = [$hopper->getInventory(), $chest];
		$before = $this->census($inventories);

		$this->h->tick();
		self::assertSame($before, $this->census($inventories));
		self::assertSame(9, $hopper->getInventory()->getItem(0)->getCount(), "nothing moves into a destroyed chest");
	}

	public function testTargetDestroyedMidPlacementKeepsItems() : void{
		HopperConfig::configure(true, 8, 64, 0);
		$hopper = $this->hopper(0, 64, 0, Facing::EAST);
		$chest = $this->chest(1, 64, 0);
		$chest->setItem(0, VanillaItems::DIAMOND()->setCount(60));
		$hopper->getInventory()->setItem(0, VanillaItems::DIAMOND()->setCount(40));
		$broken = false;
		$chest->getListeners()->add(CallbackInventoryListener::onAnyChange(function() use (&$broken) : void{
			if(!$broken){
				$broken = true;
				$this->h->breakBlock(1, 64, 0);
			}
		}));
		$inventories = [$hopper->getInventory(), $chest];
		$before = $this->census($inventories);

		$this->h->tick();
		self::assertSame($before, $this->census($inventories));
	}

	public function testHopperBrokenWhileWorkIsPending() : void{
		$hopper = $this->hopper(0, 64, 0, Facing::EAST);
		$chest = $this->chest(1, 64, 0);
		$hopper->getInventory()->setItem(0, VanillaItems::DIAMOND()->setCount(5));
		$this->h->tick();
		$inventories = [$hopper->getInventory(), $chest];
		$before = $this->census($inventories);

		$this->h->breakBlock(0, 64, 0);
		$this->h->tick(20);
		self::assertTrue($hopper->isClosed());
		self::assertSame($before, $this->census($inventories));
		self::assertSame(1, $chest->getItem(0)->getCount());
	}

	public function testHopperBrokenByListenerWhilePulling() : void{
		$chest = $this->chest(0, 65, 0);
		$hopper = $this->hopper(0, 64, 0, Facing::EAST);
		$chest->setItem(0, VanillaItems::DIAMOND()->setCount(5));
		$hopper->getInventory()->setItem(0, VanillaItems::STICK()->setCount(64));
		$this->listen(HopperTransferEvent::class, function() : void{
			$this->h->breakBlock(0, 64, 0);
		});
		$inventories = [$hopper->getInventory(), $chest];
		$before = $this->census($inventories);

		$this->h->tick();
		self::assertTrue($hopper->isClosed());
		self::assertSame($before, $this->census($inventories));
	}

	public function testDroppedItemWakesSleepingHopper() : void{
		$hopper = $this->hopper(0, 64, 0, Facing::EAST);
		$this->h->tick(2000);
		$entity = $this->world->dropItem(new Vector3(0.5, 65.5, 0.5), VanillaItems::DIAMOND()->setCount(3), Vector3::zero());
		self::assertNotNull($entity);
		$tick = $this->world->getServer()->getTick();
		$ticks = 0;
		while(++$ticks <= 30 && !$entity->isFlaggedForDespawn()){
			$entity->onUpdate($tick + $ticks);
			$this->h->tick();
		}
		self::assertTrue($entity->isFlaggedForDespawn(), "the falling item reported itself to the hopper");
		self::assertLessThan(30, $ticks);
		self::assertSame(3, $hopper->getInventory()->getItem(0)->getCount());
	}

	public function testItemsInTheBlockAboveReportToTheHopper() : void{
		$this->hopper(0, 64, 0);
		$entity = $this->world->dropItem(new Vector3(0.5, 65.4, 0.5), VanillaItems::DIAMOND(), Vector3::zero());
		self::assertNotNull($entity);
		$blocks = (new \ReflectionMethod(ItemEntity::class, "getBlocksAroundWithEntityInsideActions"))->invoke($entity);
		self::assertIsArray($blocks);
		self::assertCount(1, $blocks);
		self::assertInstanceOf(Hopper::class, $blocks[0]);
	}

	public function testSupersededScheduledUpdatesDoNotRun() : void{
		HopperConfig::configure(true, 8, 1, 1000);
		$hopper = $this->hopper(0, 64, 0);
		$lastClaim = new \ReflectionProperty(HopperConfig::class, "budgetTick");
		$delay = new \ReflectionProperty(TileHopper::class, "recheckDelay");
		$this->h->tick(300);
		$start = $this->world->getServer()->getTick();
		$next = $delay->getValue($hopper);
		self::assertIsInt($next);
		self::assertGreaterThan(300, $next, "rechecks back off while nothing happens");

		//an earlier request replaces the queued recheck, which must then be skipped when its tick comes
		$this->world->scheduleDelayedBlockUpdate(new Vector3(0, 64, 0), 5);
		$this->h->tick(5);
		self::assertSame($start + 5, $lastClaim->getValue());
		$this->h->tick($next - 1);
		self::assertSame($start + 5, $lastClaim->getValue(), "the replaced recheck didn't run");
		$this->h->tick(intdiv($next, 4) + 1);
		self::assertGreaterThan($start + 5, $lastClaim->getValue(), "the new recheck ran");
	}

	public function testConfigRejectsUnsafeValues() : void{
		foreach([[0, 1, 0], [1201, 1, 0], [8, 0, 0], [8, 65, 0], [8, 1, -1]] as [$cooldown, $items, $budget]){
			try{
				HopperConfig::configure(true, $cooldown, $items, $budget);
				self::fail("accepted $cooldown $items $budget");
			}catch(\InvalidArgumentException){
			}
		}
		self::assertSame(1, HopperConfig::getItemsPerTransfer(), "a rejected configuration changes nothing");
	}

	public function testCompetingHoppersShareOneDroppedStack() : void{
		$a = $this->hopper(0, 64, 0, Facing::DOWN);
		$b = $this->hopper(1, 64, 0, Facing::DOWN);
		foreach([$a, $b] as $hopper){
			for($slot = 0; $slot < 4; ++$slot){
				$hopper->getInventory()->setItem($slot, VanillaItems::STICK()->setCount(64));
			}
			$hopper->getInventory()->setItem(4, VanillaItems::DIAMOND()->setCount(60));
		}
		//straddles both hoppers' pickup areas
		$entity = $this->world->dropItem(new Vector3(1.0, 65.2, 0.5), VanillaItems::DIAMOND()->setCount(10), Vector3::zero());
		self::assertNotNull($entity);
		$inventories = [$a->getInventory(), $b->getInventory()];
		$before = $this->census($inventories);

		$this->h->tick(3);
		self::assertSame($before, $this->census($inventories));
		self::assertSame(2, $entity->getItem()->getCount());
	}

	public function testDespawnedOrMergedItemIsIgnored() : void{
		$hopper = $this->hopper(0, 64, 0, Facing::DOWN);
		$entity = $this->world->dropItem(new Vector3(0.5, 65.2, 0.5), VanillaItems::DIAMOND(), Vector3::zero());
		self::assertNotNull($entity);
		$entity->flagForDespawn();
		$this->h->tick(3);
		self::assertSame([], $hopper->getInventory()->getContents());
	}

	public function testDisplayItemsThatCanNeverBePickedUpStay() : void{
		$hopper = $this->hopper(0, 64, 0, Facing::DOWN);
		$entity = $this->world->dropItem(new Vector3(0.5, 65.2, 0.5), VanillaItems::DIAMOND(), Vector3::zero());
		self::assertNotNull($entity);
		$entity->setPickupDelay(ItemEntity::NEVER_DESPAWN);
		$this->h->tick(3);
		self::assertSame([], $hopper->getInventory()->getContents());
	}

	public function testShulkerBoxRefusesShulkerBoxes() : void{
		$hopper = $this->hopper(0, 64, 0, Facing::EAST);
		$this->h->set(1, 64, 0, VanillaBlocks::SHULKER_BOX());
		$box = $this->inventory(1, 64, 0);
		$hopper->getInventory()->setItem(0, VanillaBlocks::SHULKER_BOX()->asItem());
		$hopper->getInventory()->setItem(1, VanillaItems::DIAMOND());

		$this->h->tick(20);
		self::assertCount(1, $box->getContents());
		self::assertSame(VanillaItems::DIAMOND()->getTypeId(), $box->getItem(0)->getTypeId());
		self::assertFalse($hopper->getInventory()->isSlotEmpty(0));
	}

	public function testDisabledHoppersKeepTheirContents() : void{
		HopperConfig::configure(false, 8, 1, 0);
		$above = $this->chest(0, 65, 0);
		$hopper = $this->hopper(0, 64, 0, Facing::EAST);
		$target = $this->chest(1, 64, 0);
		$above->setItem(0, VanillaItems::STICK());
		$hopper->getInventory()->setItem(0, VanillaItems::DIAMOND());

		$this->h->tick(50);
		self::assertSame([], $target->getContents());
		self::assertSame(1, $hopper->getInventory()->getItem(0)->getCount());
		self::assertSame(1, $above->getItem(0)->getCount());
	}

	public function testSavedCooldownSurvivesRestartAndBadValues() : void{
		$hopper = $this->hopper(0, 64, 0, Facing::EAST);
		$this->chest(1, 64, 0);
		$hopper->getInventory()->setItem(0, VanillaItems::DIAMOND()->setCount(2));
		$this->h->tick(4);
		$nbt = $hopper->saveNBT();
		self::assertSame(5, $nbt->getInt("TransferCooldown"));

		$copy = new TileHopper();
		$copy->readSaveData($nbt);
		self::assertSame(5, $copy->getTransferCooldown());
		$this->h->set(3, 64, 3, VanillaBlocks::STONE());
		$this->world->addTile($copy, new Vector3(3, 64, 3));
		$this->h->tick(2);
		self::assertSame(3, $copy->getTransferCooldown(), "counted from the new server tick");
		self::assertSame(1, $copy->getInventory()->getItem(0)->getCount());

		foreach([[-5, 0], [1000, 8], [3, 3]] as [$saved, $expected]){
			$tile = new TileHopper();
			$tile->readSaveData(CompoundTag::create()->setInt("TransferCooldown", $saved));
			self::assertSame($expected, $tile->getTransferCooldown());
		}
		$tile = new TileHopper();
		$tile->readSaveData(CompoundTag::create()->setString("TransferCooldown", "soon"));
		self::assertSame(0, $tile->getTransferCooldown());
	}

	public function testBudgetDefersWithoutStarvingAnyHopper() : void{
		HopperConfig::configure(true, 8, 1, 3);
		$hoppers = [];
		for($x = 0; $x < 8; $x += 2){
			for($z = 0; $z < 10; ++$z){
				$hopper = $this->hopper($x, 64, $z, Facing::EAST);
				$this->chest($x + 1, 64, $z);
				$hopper->getInventory()->setItem(0, VanillaItems::DIAMOND()->setCount(64));
				$hoppers[] = $hopper;
			}
		}
		$ticks = 400;
		$this->h->tick($ticks);
		$moved = [];
		foreach($hoppers as $hopper){
			$moved[] = 64 - $hopper->getInventory()->getItem(0)->getCount();
		}
		self::assertNotSame([], $moved);
		$total = array_sum($moved);
		self::assertLessThanOrEqual(3 * $ticks, $total, "never more than the budget");
		self::assertGreaterThanOrEqual(3 * $ticks - 3, $total, "the budget is used in full");
		self::assertGreaterThanOrEqual(intdiv($total, count($hoppers)) - 2, min($moved), "no hopper falls behind");
		self::assertLessThanOrEqual(intdiv($total, count($hoppers)) + 2, max($moved));
	}

	public function testRedstoneReenabledUnlocksHopperWhoseSourceWentAway() : void{
		$hopper = $this->hopper(0, 64, 0, Facing::EAST);
		$chest = $this->chest(1, 64, 0);
		$hopper->getInventory()->setItem(0, VanillaItems::DIAMOND()->setCount(5));
		$this->h->tick();
		$this->h->place(-1, 64, 0, VanillaBlocks::REDSTONE());
		$this->h->tick(3);
		self::assertSame(1, $chest->getItem(0)->getCount());

		$this->h->manager->setWorldEnabled($this->world, false);
		$this->h->breakBlock(-1, 64, 0);
		$this->h->tick(20);
		self::assertSame(1, $chest->getItem(0)->getCount(), "a frozen circuit keeps the hopper locked");

		$this->h->manager->setWorldEnabled($this->world, true);
		$this->h->tick(3);
		self::assertSame(2, $chest->getItem(0)->getCount());
	}

	public function testChunkUnloadPausesTransfersAndLeavesNoListeners() : void{
		$this->h->set(15, 64, 0, VanillaBlocks::CHEST());
		$chest = $this->inventory(15, 64, 0);
		for($slot = 0; $slot < 27; ++$slot){
			$chest->setItem($slot, VanillaItems::STICK()->setCount(64));
		}
		for($cycle = 0; $cycle < 30; ++$cycle){
			$hopper = $this->hopper(16, 64, 0, Facing::WEST);
			$hopper->getInventory()->setItem(0, VanillaItems::DIAMOND());
			$this->h->tick(2);
			self::assertCount(1, $chest->getListeners()->toArray(), "only the live hopper watches the chest");
			$this->h->unloadChunk(1, 0);
			self::assertTrue($hopper->isClosed());
			$this->h->tick(2);
			$this->h->loadChunk(1, 0);
		}
		self::assertCount(0, $chest->getListeners()->toArray());

		$hopper = $this->hopper(16, 64, 0, Facing::WEST);
		$hopper->getInventory()->setItem(0, VanillaItems::DIAMOND());
		$this->h->unloadChunk(0, 0);
		$this->h->tick(30);
		self::assertSame(1, $hopper->getInventory()->getItem(0)->getCount(), "no transfer toward unloaded terrain");
		$this->h->loadChunk(0, 0);
		$this->h->set(15, 64, 0, VanillaBlocks::CHEST());
		$this->h->tick(2);
		self::assertSame(1, $this->inventory(15, 64, 0)->getItem(0)->getCount(), "loading the chunk back resumes the hopper");
	}

	/**
	 * Random networks of hoppers and containers, with players shuffling items and hoppers being broken and replaced.
	 */
	public function testRandomNetworksNeverCreateOrDestroyItems() : void{
		$palette = [
			VanillaItems::DIAMOND(), VanillaItems::STICK(), VanillaItems::APPLE(), VanillaItems::DIAMOND_SWORD(),
			VanillaItems::DIAMOND()->setCustomName("Tagged"), VanillaItems::ENDER_PEARL(), VanillaItems::POTION()
		];
		$facings = [Facing::DOWN, Facing::NORTH, Facing::SOUTH, Facing::EAST, Facing::WEST];
		foreach([3, 17, 41] as $seed){
			$this->setUp();
			mt_srand($seed);
			HopperConfig::configure(true, mt_rand(1, 8), mt_rand(1, 4), mt_rand(0, 1) === 0 ? 0 : mt_rand(1, 20));
			$positions = [];
			for($x = 0; $x < 6; ++$x){
				for($y = 64; $y < 67; ++$y){
					for($z = 0; $z < 6; ++$z){
						$roll = mt_rand(0, 99);
						if($roll < 50){
							$this->h->set($x, $y, $z, VanillaBlocks::HOPPER()->setFacing($facings[mt_rand(0, 4)]));
						}elseif($roll < 70){
							$this->h->set($x, $y, $z, mt_rand(0, 1) === 0 ? VanillaBlocks::CHEST() : VanillaBlocks::BARREL());
						}else{
							continue;
						}
						$positions[] = [$x, $y, $z];
						$inventory = $this->inventory($x, $y, $z);
						for($i = mt_rand(0, 4); $i > 0; --$i){
							$inventory->setItem(mt_rand(0, $inventory->getSize() - 1), (clone $palette[mt_rand(0, count($palette) - 1)])->setCount(mt_rand(1, 64)));
						}
					}
				}
			}
			$inventories = fn() : array => array_map(fn(array $p) : Inventory => $this->inventory(...$p), $positions);
			$before = $this->census($inventories());
			for($tick = 0; $tick < 600; ++$tick){
				if(mt_rand(0, 2) === 0){
					//a player swapping two stacks between containers
					$all = $inventories();
					$a = $all[mt_rand(0, count($all) - 1)];
					$b = $all[mt_rand(0, count($all) - 1)];
					$slotA = mt_rand(0, $a->getSize() - 1);
					$slotB = mt_rand(0, $b->getSize() - 1);
					if($a !== $b || $slotA !== $slotB){
						$itemA = $a->getItem($slotA);
						$a->setItem($slotA, $b->getItem($slotB));
						$b->setItem($slotB, $itemA);
					}
				}
				if(mt_rand(0, 40) === 0){
					[$x, $y, $z] = $positions[mt_rand(0, count($positions) - 1)];
					if($this->world->getBlockAt($x, $y, $z) instanceof Hopper){
						$this->h->breakBlock($x, $y, $z);
						$this->h->set($x, $y, $z, VanillaBlocks::HOPPER()->setFacing($facings[mt_rand(0, 4)]));
					}
				}
				$this->h->tick();
				self::assertSame($before, $this->census($inventories()), "seed $seed tick $tick");
			}
		}
	}
}
