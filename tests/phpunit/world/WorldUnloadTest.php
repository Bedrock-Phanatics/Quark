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
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\RegisteredListener;
use quark\event\world\ChunkUnloadEvent;
use quark\item\VanillaItems;
use quark\plugin\Plugin;
use quark\pulse\Pulse;
use quark\world\format\io\ChunkData;
use quark\world\format\io\WorldData;
use quark\world\format\io\WritableWorldProvider;
use quark\world\generator\executor\GeneratorExecutor;
use quark\world\redstone\RedstoneHarness;
use function count;

final class WorldUnloadTest extends TestCase{
	private Plugin $plugin;
	/** @var array<int, int> chunk hash => saved entity count */
	private array $savedEntities = [];

	protected function setUp() : void{
		$this->plugin = $this->createMock(Plugin::class);
	}

	protected function tearDown() : void{
		HandlerListManager::global()->unregisterAll($this->plugin);
	}

	private function world() : World{
		$world = (new RedstoneHarness(0))->world;
		$provider = $this->createMock(WritableWorldProvider::class);
		$provider->method("getWorldData")->willReturn($this->createMock(WorldData::class));
		$provider->method("saveChunk")->willReturnCallback(function(int $x, int $z, ChunkData $data) : void{
			$this->savedEntities[World::chunkHash($x, $z)] = count($data->getEntityNBT());
		});
		(new \ReflectionProperty(World::class, "provider"))->setValue($world, $provider);
		(new \ReflectionProperty(World::class, "generatorExecutor"))->setValue($world, $this->createMock(GeneratorExecutor::class));
		(new \ReflectionProperty(World::class, "time"))->setValue($world, 0);
		(new \ReflectionProperty(World::class, "weatherDuration"))->setValue($world, 0);
		(new ItemEntity(new Location(0.5, 1, 0.5, $world, 0, 0), VanillaItems::STICK()))->spawnToAll();
		return $world;
	}

	private function listen(\Closure $handler) : void{
		HandlerListManager::global()->getListFor(ChunkUnloadEvent::class)->register(new RegisteredListener(
			$handler, EventPriority::NORMAL, $this->plugin, false, Pulse::zone("world.test")
		));
	}

	public function testCancelledChunkUnloadStillSavesEntitiesOnWorldUnload() : void{
		$world = $this->world();
		$this->listen(static fn(ChunkUnloadEvent $event) => $event->cancel());

		$world->onUnload();

		self::assertSame([World::chunkHash(0, 0) => 1], $this->savedEntities);
		self::assertFalse($world->isLoaded());
	}

	public function testThrowingChunkUnloadListenerStillSavesEntities() : void{
		$world = $this->world();
		$this->listen(static fn(ChunkUnloadEvent $event) => throw new \RuntimeException("listener failure"));

		try{
			$world->onUnload();
			self::fail("The listener failure should be rethrown");
		}catch(\RuntimeException $e){
			self::assertSame("listener failure", $e->getMessage());
		}

		self::assertSame([World::chunkHash(0, 0) => 1], $this->savedEntities);
		self::assertFalse($world->isLoaded());
	}

	public function testThrowingUnloadCallbackStillSavesTheWorld() : void{
		$world = $this->world();
		$world->addOnUnloadCallback(static fn() => throw new \RuntimeException("callback failure"));

		try{
			$world->onUnload();
			self::fail("The callback failure should be rethrown");
		}catch(\RuntimeException $e){
			self::assertSame("callback failure", $e->getMessage());
		}

		self::assertSame([World::chunkHash(0, 0) => 1], $this->savedEntities);
		self::assertFalse($world->isLoaded());
	}
}
