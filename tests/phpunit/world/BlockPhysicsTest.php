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
use quark\entity\Location;
use quark\entity\object\ItemEntity;
use quark\entity\Zombie;
use quark\event\block\BlockUpdateEvent;
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
use function mt_rand;
use function mt_srand;
use function sort;

final class BlockPhysicsTest extends TestCase{

	protected function tearDown() : void{
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
		(new PluginManager($this->createMock(Server::class), null))->registerEvent($event, $handler, EventPriority::NORMAL, $plugin);
	}

	private static function runNeighbourPass(World $world) : void{
		\Closure::bind(fn() => $this->runNeighbourBlockUpdates(), $world, World::class)();
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
}
