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
use quark\block\Farmland;
use quark\block\VanillaBlocks;
use quark\Server;
use quark\world\redstone\RedstoneHarness;
use function array_column;
use function array_unique;

final class WorldRandomTickTest extends TestCase{
	private const SUBCHUNK = 4;

	private RedstoneHarness $harness;

	protected function setUp() : void{
		$this->harness = new RedstoneHarness(2);
		$world = $this->harness->world;
		(new \ReflectionProperty(World::class, "chunkTickRadius"))->setValue($world, 1);
		for($x = -2; $x <= 2; ++$x){
			for($z = -2; $z <= 2; ++$z){
				$world->getChunk($x, $z)?->setLightPopulated(true);
			}
		}
		$world->addRandomTickedBlock(self::wetFarmland());
		$world->registerTickingChunk(new ChunkTicker(), 0, 0);
	}

	private static function wetFarmland() : Farmland{
		return VanillaBlocks::FARMLAND()->setWetness(Farmland::MAX_WETNESS);
	}

	private function tickChunks(int $ticks = 1) : void{
		$world = $this->harness->world;
		$advance = \Closure::bind(fn() : int => ++$this->tickCounter, $world->getServer(), Server::class);
		$tick = \Closure::bind(fn() => $this->tickChunks(), $world, World::class);
		for($i = 0; $i < $ticks; ++$i){
			$advance();
			$tick();
		}
	}

	/** Dry farmland loses wetness on each random tick, so a drop shows the subchunk was ticked. */
	private function driedBlocks() : int{
		$dried = 0;
		for($i = 0; $i < 4096; ++$i){
			$block = $this->harness->world->getBlockAt($i & 15, (self::SUBCHUNK << 4) + (($i >> 4) & 15), $i >> 8, cached: false, addToCache: false);
			if($block instanceof Farmland && $block->getWetness() < Farmland::MAX_WETNESS){
				++$dried;
			}
		}
		return $dried;
	}

	public function testBlocksPlacedThroughTheWorldTickAtOnce() : void{
		$this->tickChunks();
		$farmland = self::wetFarmland();
		for($i = 0; $i < 4096; ++$i){
			$this->harness->world->setBlockAt($i & 15, (self::SUBCHUNK << 4) + (($i >> 4) & 15), $i >> 8, $farmland, false);
		}
		self::assertSame(0, $this->driedBlocks());
		$this->tickChunks();
		self::assertGreaterThan(0, $this->driedBlocks());
	}

	public function testPlacingTickedBlocksKeepsTheScheduledRescan() : void{
		$world = $this->harness->world;
		$this->tickChunks();
		$scan = static function() use ($world) : mixed{
			$scans = (new \ReflectionProperty(World::class, "randomTickSubChunks"))->getValue($world);
			self::assertIsArray($scans);
			return $scans[World::chunkHash(0, 0)];
		};
		$before = $scan();
		self::assertIsArray($before);

		$world->setBlockAt(0, (self::SUBCHUNK << 4) + 1, 0, self::wetFarmland(), false);
		$world->setBlockAt(1, (self::SUBCHUNK << 4) + 1, 0, self::wetFarmland(), false);
		$world->setBlockAt(0, 1, 0, self::wetFarmland(), false);

		self::assertSame([$before[0], [0, self::SUBCHUNK]], $scan());
	}

	public function testLayersChangedOutsideTheWorldAreRescanned() : void{
		$this->tickChunks();
		$chunk = $this->harness->world->getChunk(0, 0) ?? throw new \AssertionError("Chunk is not loaded");
		$state = self::wetFarmland()->getStateId();
		for($i = 0; $i < 4096; ++$i){
			$chunk->setBlockStateId($i & 15, (self::SUBCHUNK << 4) + (($i >> 4) & 15), $i >> 8, $state);
		}
		self::assertSame(0, $this->driedBlocks());
		$this->tickChunks(16);
		self::assertGreaterThan(0, $this->driedBlocks());
	}

	public function testNeighbouringChunksRescanOnDifferentTicks() : void{
		$world = $this->harness->world;
		for($x = -1; $x <= 1; ++$x){
			for($z = -1; $z <= 1; ++$z){
				$world->registerTickingChunk(new ChunkTicker(), $x, $z);
			}
		}
		$this->tickChunks();
		$scans = (new \ReflectionProperty(World::class, "randomTickSubChunks"))->getValue($world);
		self::assertIsArray($scans);
		self::assertCount(9, $scans);
		self::assertCount(9, array_unique(array_column($scans, 0)));
	}
}
