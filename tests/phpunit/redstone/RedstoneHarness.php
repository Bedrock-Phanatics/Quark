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

use quark\block\Block;
use quark\block\RuntimeBlockStateRegistry;
use quark\block\tile\comparator\ComparatorWeightRegistry;
use quark\block\VanillaBlocks;
use quark\item\VanillaItems;
use quark\math\collision\CollisionRegion;
use quark\pulse\internal\PulseZones;
use quark\Server;
use quark\utils\ReversePriorityQueue;
use quark\world\format\Chunk;
use quark\world\World;
use quark\world\WorldPulse;
use function spl_object_id;

/**
 * In-memory world for driving redstone circuits in tests without a server.
 */
final class RedstoneHarness{

	public readonly World $world;
	public readonly RedstoneManager $manager;
	/** @var list<string> */
	public array $warnings = [];
	private Server $server;

	public function __construct(int $chunkRadius = 1){
		PulseZones::init();
		ComparatorWeightRegistry::init();
		VanillaBlocks::AIR();
		RedstoneConfig::configure(65536, 262144, 8192, true, true, 1024, 4096);

		$this->server = (new \ReflectionClass(Server::class))->newInstanceWithoutConstructor();
		$world = (new \ReflectionClass(World::class))->newInstanceWithoutConstructor();
		$set = static function(string $property, mixed $value) use ($world) : void{
			(new \ReflectionProperty(World::class, $property))->setValue($world, $value);
		};
		$set("server", $this->server);
		$set("folderName", "redstone_test");
		$set("displayName", "redstone_test");
		$set("logger", new class(function(string $message) : void{ $this->warnings[] = $message; }) extends \SimpleLogger{
			/** @phpstan-param \Closure(string) : void $onWarning */
			public function __construct(private \Closure $onWarning){}

			public function warning($message){
				($this->onWarning)($message);
			}
		});
		$set("worldId", 0x7fff0000 + spl_object_id($world));
		$set("blockStateRegistry", RuntimeBlockStateRegistry::getInstance());
		$set("minY", World::Y_MIN);
		$set("maxY", World::Y_MAX);
		$set("collisionRegion", new CollisionRegion());
		$queue = new ReversePriorityQueue();
		$queue->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
		$set("scheduledBlockUpdateQueue", $queue);
		$set("neighbourBlockUpdateQueue", new \SplQueue());
		$set("pulse", new WorldPulse($world));
		$chunks = [];
		for($x = -$chunkRadius; $x <= $chunkRadius; ++$x){
			for($z = -$chunkRadius; $z <= $chunkRadius; ++$z){
				$chunks[World::chunkHash($x, $z)] = new Chunk([], true);
			}
		}
		$set("chunks", $chunks);
		$this->world = $world;

		$this->manager = (new \ReflectionClass(RedstoneManager::class))->newInstanceWithoutConstructor();
		(new \ReflectionProperty(RedstoneManager::class, "instance"))->setValue(null, $this->manager);
		$this->manager->load($world);
	}

	public function state() : RedstoneWorldState{
		return $this->manager->get($this->world);
	}

	public function set(int $x, int $y, int $z, Block $block) : void{
		$this->world->setBlockAt($x, $y, $z, $block);
	}

	/** Places the block the way a player would, so post-place hooks run. */
	public function place(int $x, int $y, int $z, Block $block) : void{
		$this->world->setBlockAt($x, $y, $z, $block);
		$this->world->getBlockAt($x, $y, $z)->onPostPlace();
	}

	public function get(int $x, int $y, int $z) : Block{
		return $this->world->getBlockAt($x, $y, $z);
	}

	public function breakBlock(int $x, int $y, int $z) : void{
		$this->world->getBlockAt($x, $y, $z)->onBreak(VanillaItems::AIR());
	}

	/** Returns the unloaded chunk so it can be loaded back with its blocks. */
	public function unloadChunk(int $chunkX, int $chunkZ) : Chunk{
		$chunk = $this->world->getChunk($chunkX, $chunkZ) ?? throw new \RuntimeException("Chunk $chunkX $chunkZ is not loaded");
		if(!$this->world->unloadChunk($chunkX, $chunkZ, false, false)){
			throw new \RuntimeException("Chunk $chunkX $chunkZ did not unload");
		}
		return $chunk;
	}

	public function loadChunk(int $chunkX, int $chunkZ, ?Chunk $chunk = null) : void{
		$chunk ??= new Chunk([], true);
		\Closure::bind(function() use ($chunkX, $chunkZ, $chunk) : void{
			$this->chunks[World::chunkHash($chunkX, $chunkZ)] = $chunk;
		}, $this->world, World::class)();
		foreach($this->world->getChunkListeners($chunkX, $chunkZ) as $listener){
			$listener->onChunkLoaded($chunkX, $chunkZ, $chunk);
		}
	}

	/** Runs world block updates and the redstone scheduler for the given number of game ticks. */
	public function tick(int $ticks = 1) : void{
		$advance = \Closure::bind(fn() : int => ++$this->tickCounter, $this->server, Server::class);
		$update = \Closure::bind(function(int $tick) : void{
			$this->runScheduledBlockUpdates($tick);
			$this->runNeighbourBlockUpdates();
		}, $this->world, World::class);
		for($i = 0; $i < $ticks; ++$i){
			$update($advance());
			$this->manager->tick();
		}
	}
}
