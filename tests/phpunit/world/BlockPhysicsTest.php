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

namespace quark\world;

use PHPUnit\Framework\TestCase;
use quark\block\Block;
use quark\block\RuntimeBlockStateRegistry;
use quark\block\VanillaBlocks;
use quark\entity\Location;
use quark\entity\object\FallingBlock;
use quark\entity\object\ItemEntity;
use quark\entity\Zombie;
use quark\event\block\BlockSpreadEvent;
use quark\event\block\BlockUpdateEvent;
use quark\event\entity\EntityBlockChangeEvent;
use quark\event\Event;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\item\VanillaItems;
use quark\math\AxisAlignedBB;
use quark\math\Facing;
use quark\math\Vector3;
use quark\plugin\Plugin;
use quark\plugin\PluginManager;
use quark\Server;
use quark\world\redstone\RedstoneHarness;
use function array_keys;
use function array_values;
use function count;
use function mt_rand;
use function mt_srand;
use function sort;

final class BlockPhysicsTest extends TestCase{

	protected function tearDown() : void{
		FallingBlock::setFastFalling(false);
		HandlerListManager::global()->unregisterAll();
	}

	/**
	 * @phpstan-template TEvent of Event
	 * @phpstan-param class-string<TEvent>    $event
	 * @phpstan-param \Closure(TEvent) : void $handler
	 */
	private function listen(string $event, \Closure $handler) : void{
		$plugin = self::createStub(Plugin::class);
		$plugin->method("isEnabled")->willReturn(true);
		(new PluginManager($this->createMock(Server::class)))->registerEvent($event, $handler, EventPriority::NORMAL, $plugin);
	}

	private static function runNeighbourPass(World $world) : void{
		\Closure::bind(fn() => $this->runNeighbourBlockUpdates(), $world, World::class)();
	}

	/** @return list<FallingBlock> */
	private static function fallingBlocks(World $world) : array{
		$found = [];
		foreach($world->getEntities() as $entity){
			if($entity instanceof FallingBlock && !$entity->isClosed()){
				$found[] = $entity;
			}
		}
		return $found;
	}

	/** @return list<ItemEntity> */
	private static function items(World $world) : array{
		$found = [];
		foreach($world->getEntities() as $entity){
			//merged items are flagged and only close on their next tick
			if($entity instanceof ItemEntity && !$entity->isClosed() && !$entity->isFlaggedForDespawn()){
				$found[] = $entity;
			}
		}
		return $found;
	}

	private static function settle(RedstoneHarness $h, int $maxTicks = 400) : void{
		for($i = 0; $i < $maxTicks && count(self::fallingBlocks($h->world)) > 0; ++$i){
			$h->tickWithEntities();
		}
		self::assertSame([], self::fallingBlocks($h->world), "falling blocks did not settle");
	}

	/** Places blocks upwards from x,y,z without updates, then wakes the lowest one. */
	private static function drop(RedstoneHarness $h, int $x, int $y, int $z, Block ...$blocks) : void{
		foreach(array_values($blocks) as $i => $block){
			$h->world->setBlockAt($x, $y + $i, $z, $block, false);
		}
		$h->world->notifyNeighbourBlockUpdate(new Vector3($x, $y, $z));
		$h->tickWithEntities();
	}

	/** Starts one block falling without moving any entity yet. */
	private static function release(RedstoneHarness $h, int $x, int $y, int $z, Block $block) : FallingBlock{
		$h->world->setBlockAt($x, $y, $z, $block, false);
		$h->world->notifyNeighbourBlockUpdate(new Vector3($x, $y, $z));
		self::runNeighbourPass($h->world);
		foreach(self::fallingBlocks($h->world) as $entity){
			if($entity->getPosition()->getFloorX() === $x && $entity->getPosition()->getFloorZ() === $z){
				return $entity;
			}
		}
		self::fail("No falling block at $x $z");
	}

	/** @return list<int> */
	private static function column(World $world, int $x, int $z, int $minY, int $maxY) : array{
		$states = [];
		for($y = $minY; $y <= $maxY; ++$y){
			$states[] = $world->getBlockAt($x, $y, $z)->getStateId();
		}
		return $states;
	}

	/** @return array<int, int> */
	private static function fallingBlockCounts(World $world) : array{
		$counts = (new \ReflectionProperty(World::class, "fallingBlockCounts"))->getValue($world);
		self::assertIsArray($counts);
		/** @var array<int, int> $counts */
		return $counts;
	}

	public function testFallsFromItsOwnPositionByDefault() : void{
		$h = new RedstoneHarness(1);
		$h->world->setBlockAt(0, 0, 0, VanillaBlocks::STONE(), false);
		$falling = self::release($h, 0, 20, 0, VanillaBlocks::SAND());
		self::assertSame(20.0, $falling->getPosition()->y);
		self::assertSame(0.0, $falling->getFallDistance());
		self::settle($h);
		self::assertTrue($h->get(0, 1, 0)->hasSameTypeId(VanillaBlocks::SAND()));
		self::assertSame([], self::fallingBlockCounts($h->world));
	}

	public function testFastFallStartsAboveTheLandingSpot() : void{
		FallingBlock::setFastFalling(true);
		$h = new RedstoneHarness(1);
		$changes = 0;
		$this->listen(EntityBlockChangeEvent::class, function(EntityBlockChangeEvent $event) use (&$changes) : void{
			++$changes;
		});
		$h->world->setBlockAt(0, 0, 0, VanillaBlocks::STONE(), false);
		$falling = self::release($h, 0, 20, 0, VanillaBlocks::SAND());
		self::assertSame(1.0, $falling->getPosition()->y);
		self::assertSame(19.0, $falling->getFallDistance());
		self::assertTrue($h->get(0, 20, 0)->hasSameTypeId(VanillaBlocks::AIR()));
		self::settle($h, 5);
		self::assertTrue($h->get(0, 1, 0)->hasSameTypeId(VanillaBlocks::SAND()));
		self::assertSame(1, $changes);
		self::assertSame([], self::items($h->world));
		self::assertSame([], self::fallingBlockCounts($h->world));
	}

	public function testCancelledFastLandingPlacesNothingLikeNormal() : void{
		$this->listen(EntityBlockChangeEvent::class, function(EntityBlockChangeEvent $event) : void{
			$event->cancel();
		});
		foreach([false, true] as $fast){
			FallingBlock::setFastFalling($fast);
			$h = new RedstoneHarness(1);
			$h->world->setBlockAt(0, 0, 0, VanillaBlocks::STONE(), false);
			self::drop($h, 0, 20, 0, VanillaBlocks::SAND());
			self::settle($h);
			self::assertSame(self::column($h->world, 0, 0, 1, 21), self::column((new RedstoneHarness(1))->world, 0, 0, 1, 21));
			self::assertSame([], self::items($h->world));
		}
	}

	/**
	 * @phpstan-param \Closure(RedstoneHarness) : void $build
	 * @return array{list<int>, int}
	 */
	private static function outcome(bool $fast, \Closure $build, int $x, int $z, int $minY, int $maxY) : array{
		FallingBlock::setFastFalling($fast);
		$h = new RedstoneHarness(1);
		$build($h);
		self::settle($h);
		$items = 0;
		foreach(self::items($h->world) as $item){
			$items += $item->getItem()->getCount();
		}
		self::assertSame([], self::fallingBlockCounts($h->world));
		return [self::column($h->world, $x, $z, $minY, $maxY), $items];
	}

	private static function floor(RedstoneHarness $h, int $y) : void{
		for($x = -4; $x <= 10; ++$x){
			for($z = -4; $z <= 10; ++$z){
				$h->world->setBlockAt($x, $y, $z, VanillaBlocks::STONE(), false);
			}
		}
	}

	public function testFastTowersEndUpLikeNormalOnes() : void{
		$build = function(RedstoneHarness $h) : void{
			self::floor($h, World::Y_MIN);
			$h->world->setBlockAt(3, World::Y_MIN + 1, 3, VanillaBlocks::TALL_GRASS(), false);
			$tower = [];
			for($i = 0; $i < 120; ++$i){
				$tower[] = $i % 3 === 0 ? VanillaBlocks::GRAVEL() : VanillaBlocks::SAND();
			}
			$tower[] = VanillaBlocks::CONCRETE_POWDER();
			$tower[] = VanillaBlocks::SAND();
			self::drop($h, 3, 10, 3, ...$tower);
		};
		$normal = self::outcome(false, $build, 3, 3, World::Y_MIN, 140);
		self::assertSame(0, $normal[1]);
		self::assertSame($normal, self::outcome(true, $build, 3, 3, World::Y_MIN, 140));
	}

	public function testFastTowerOnATorchPopsOffLikeNormal() : void{
		$build = function(RedstoneHarness $h) : void{
			self::floor($h, 0);
			$h->world->setBlockAt(3, 1, 3, VanillaBlocks::TORCH(), false);
			self::drop($h, 3, 30, 3, VanillaBlocks::SAND(), VanillaBlocks::GRAVEL(), VanillaBlocks::SAND());
		};
		$normal = self::outcome(false, $build, 3, 3, 0, 40);
		self::assertSame(3, $normal[1]);
		self::assertSame($normal, self::outcome(true, $build, 3, 3, 0, 40));
	}

	public function testFastLandingOnASlabDropsLikeNormal() : void{
		$build = function(RedstoneHarness $h) : void{
			self::floor($h, -1);
			$h->world->setBlockAt(0, 0, 0, VanillaBlocks::SMOOTH_STONE_SLAB(), false);
			self::drop($h, 0, 30, 0, VanillaBlocks::SAND(), VanillaBlocks::SAND());
		};
		$normal = self::outcome(false, $build, 0, 0, 0, 40);
		self::assertSame(2, $normal[1], "sand landing on a slab pops off as an item");
		self::assertSame($normal, self::outcome(true, $build, 0, 0, 0, 40));
	}

	public function testBlocksThatActWhileFallingAreNotShortened() : void{
		FallingBlock::setFastFalling(true);
		$h = new RedstoneHarness(1);
		$h->world->setBlockAt(0, 0, 0, VanillaBlocks::STONE(), false);
		self::assertSame(20.0, self::release($h, 0, 20, 0, VanillaBlocks::CONCRETE_POWDER())->getPosition()->y);
		self::assertFalse(FallingBlock::canFallFast(VanillaBlocks::CONCRETE_POWDER()));
		self::assertTrue(FallingBlock::canFallFast(VanillaBlocks::ANVIL()));
	}

	public function testFallIntoTheVoidIsNotShortened() : void{
		FallingBlock::setFastFalling(true);
		$h = new RedstoneHarness(1);
		self::assertSame(20.0, self::release($h, 0, 20, 0, VanillaBlocks::SAND())->getPosition()->y);
	}

	public function testFastFallLandsOnTheWorldFloor() : void{
		FallingBlock::setFastFalling(true);
		$h = new RedstoneHarness(1);
		$h->world->setBlockAt(0, World::Y_MIN, 0, VanillaBlocks::STONE(), false);
		self::assertSame((float) (World::Y_MIN + 1), self::release($h, 0, -10, 0, VanillaBlocks::SAND())->getPosition()->y);
		self::settle($h);
		self::assertTrue($h->get(0, World::Y_MIN + 1, 0)->hasSameTypeId(VanillaBlocks::SAND()));
	}

	public function testBlocksStillInTheAirDisableTheShortcutForTheirChunk() : void{
		$h = new RedstoneHarness(1);
		for($x = 0; $x < 3; ++$x){
			$h->world->setBlockAt($x, 0, 0, VanillaBlocks::STONE(), false);
		}
		$h->world->setBlockAt(17, 0, 0, VanillaBlocks::STONE(), false);
		self::drop($h, 0, 60, 0, VanillaBlocks::SAND());
		FallingBlock::setFastFalling(true);
		//the first sand is still falling in chunk 0, so only the one in chunk 1 skips ahead
		self::assertSame(40.0, self::release($h, 1, 40, 0, VanillaBlocks::SAND())->getPosition()->y);
		self::assertSame(1.0, self::release($h, 17, 40, 0, VanillaBlocks::SAND())->getPosition()->y);
		self::settle($h);
		self::assertSame([], self::fallingBlockCounts($h->world));

		self::assertSame(1.0, self::release($h, 2, 40, 0, VanillaBlocks::SAND())->getPosition()->y);
	}

	public function testAFallingBlockMovedDuringThePassDisablesTheShortcut() : void{
		FallingBlock::setFastFalling(true);
		$h = new RedstoneHarness(1);
		for($x = 0; $x < 3; ++$x){
			$h->world->setBlockAt($x, 0, 0, VanillaBlocks::STONE(), false);
		}
		$pass = new \ReflectionProperty(World::class, "fallingStarts");
		$pass->setValue($h->world, []);
		try{
			//a plugin can spawn one directly; its start is still known
			$spawned = new FallingBlock(new Location(0.5, 50.25, 0.5, $h->world, 0, 0), VanillaBlocks::SAND());
			self::assertSame(1, $h->world->startFallingBlock(1, 40, 0, true));
			self::assertSame(52, $h->world->startFallingBlock(0, 60, 0, true), "a full block above one caught mid-fall");
			self::assertSame(45, $h->world->startFallingBlock(0, 45, 0, true));
			$spawned->teleport(new Vector3(0.5, 30, 0.5));
			self::assertSame(40, $h->world->startFallingBlock(1, 40, 0, true));
			self::assertSame(40, $h->world->startFallingBlock(2, 40, 0, false));
		}finally{
			$pass->setValue($h->world, null);
		}
		self::assertSame(40, $h->world->startFallingBlock(1, 40, 0, true), "outside a pass nothing is known");
	}

	public function testUnloadingAChunkReleasesItsFallingBlocks() : void{
		$h = new RedstoneHarness(1);
		$h->world->setBlockAt(16, 0, 0, VanillaBlocks::STONE(), false);
		self::drop($h, 16, 100, 0, VanillaBlocks::SAND(), VanillaBlocks::SAND());
		self::assertCount(2, self::fallingBlocks($h->world));
		self::assertSame([World::chunkHash(1, 0) => 2], self::fallingBlockCounts($h->world));
		$h->unloadChunk(1, 0);
		self::assertSame([], self::fallingBlockCounts($h->world));
	}

	public function testFastAnvilHitsAsHardAsANormalOne() : void{
		$health = [];
		foreach([false, true] as $fast){
			FallingBlock::setFastFalling($fast);
			$h = new RedstoneHarness(1);
			//the damage event looks its damager up through the world manager
			(new \ReflectionProperty(Server::class, "worldManager"))->setValue($h->world->getServer(), (new \ReflectionClass(WorldManager::class))->newInstanceWithoutConstructor());
			$h->world->setBlockAt(0, 0, 0, VanillaBlocks::STONE(), false);
			$zombie = new Zombie(new Location(0.5, 1, 0.5, $h->world, 0, 0));
			$zombie->spawnToAll();
			$h->tickWithEntities(5);
			self::drop($h, 0, 12, 0, VanillaBlocks::ANVIL());
			self::settle($h);
			$health[] = $zombie->getHealth();
		}
		self::assertLessThan(20.0, $health[0]);
		self::assertSame($health[0], $health[1]);
	}

	public function testEntitiesHearAboutTheSameBlockUpdatesAsBefore() : void{
		$world = (new RedstoneHarness(1))->world;
		mt_srand(42);
		for($i = 0; $i < 300; ++$i){
			//whole and half coordinates put boxes exactly on block faces and chunk edges
			(new ItemEntity(new Location(mt_rand(-30, 30) / 2, mt_rand(0, 16) / 2, mt_rand(-30, 30) / 2, $world, 0, 0), VanillaItems::STICK()))->spawnToAll();
		}
		for($i = 0; $i < 20; ++$i){
			//boxes with more cells than a pass updates take the other path
			$zombie = new Zombie(new Location(mt_rand(-20, 20) / 2, mt_rand(0, 8) / 2, mt_rand(-20, 20) / 2, $world, 0, 0));
			$zombie->setScale(4);
			$zombie->spawnToAll();
		}
		$woken = [];
		for($round = 0; $round < 40; ++$round){
			$world->updateEntities = [];
			$expected = [];
			for($i = 0, $updates = $round % 2 === 0 ? 1 : 40; $i < $updates; ++$i){
				//inside the loaded 3x3 chunks, as updates next to unloaded terrain wait for it
				$x = mt_rand(-14, 14);
				$y = mt_rand(0, 8);
				$z = mt_rand(-14, 14);
				$world->notifyNeighbourBlockUpdate(new Vector3($x, $y, $z));
				foreach([[0, 0, 0], ...Facing::OFFSET] as [$dx, $dy, $dz]){
					foreach($world->getNearbyEntities(AxisAlignedBB::one()->offset($x + $dx, $y + $dy, $z + $dz)) as $entity){
						$expected[$entity->getId()] = true;
					}
				}
			}
			self::runNeighbourPass($world);
			$expectedIds = array_keys($expected);
			$actualIds = array_keys($world->updateEntities);
			sort($expectedIds);
			sort($actualIds);
			self::assertSame($expectedIds, $actualIds, "round $round");
			foreach($expectedIds as $id){
				$woken[$world->getEntity($id) instanceof Zombie ? "large" : "small"] = true;
			}
		}
		self::assertTrue(isset($woken["large"], $woken["small"]), "both box sizes were woken");
	}

	public function testCancelledBlockUpdatesDoNotWakeEntities() : void{
		$h = new RedstoneHarness(1);
		(new ItemEntity(new Location(0.5, 1, 0.5, $h->world, 0, 0), VanillaItems::STICK()))->spawnToAll();
		$this->listen(BlockUpdateEvent::class, function(BlockUpdateEvent $event) : void{
			$event->cancel();
		});
		$h->world->updateEntities = [];
		$h->world->notifyNeighbourBlockUpdate(new Vector3(0, 1, 0));
		self::runNeighbourPass($h->world);
		self::assertSame([], $h->world->updateEntities);
	}

	public function testCancelledSpreadKeepsLiquidInPlace() : void{
		$h = new RedstoneHarness(1);
		for($x = -4; $x <= 4; ++$x){
			for($z = -4; $z <= 4; ++$z){
				$h->world->setBlockAt($x, 0, $z, VanillaBlocks::STONE(), false);
			}
		}
		$this->listen(BlockSpreadEvent::class, function(BlockSpreadEvent $event) : void{
			if($event->getBlock()->getPosition()->x > 0){
				$event->cancel();
			}
		});
		$h->world->setBlockAt(0, 1, 0, VanillaBlocks::WATER());
		$h->tick(60);
		self::assertTrue($h->get(-1, 1, 0)->hasSameTypeId(VanillaBlocks::WATER()));
		self::assertTrue($h->get(1, 1, 0)->hasSameTypeId(VanillaBlocks::AIR()));
	}

	public function testFlowableStatesMatchTheirBlocks() : void{
		$registry = RuntimeBlockStateRegistry::getInstance();
		foreach($registry->getAllKnownStates() as $stateId => $block){
			self::assertSame($block->canBeFlowedInto(), isset($registry->flowable[$stateId]), $block->getName());
		}
	}
}
