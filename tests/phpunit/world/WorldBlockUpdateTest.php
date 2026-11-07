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
use pocketmine\math\AxisAlignedBB;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use quark\block\tile\Chest as TileChest;
use quark\block\VanillaBlocks;
use quark\network\mcpe\convert\TypeConverter;
use quark\world\format\Chunk;
use quark\world\redstone\RedstoneHarness;
use function array_map;
use function count;

final class WorldBlockUpdateTest extends TestCase{

	private World $world;

	protected function setUp() : void{
		$this->world = (new RedstoneHarness(2))->world;
	}

	public function testChunkBlockHashDecodesToLocalCoordinates() : void{
		$seen = [];
		foreach([-33, -17, -16, -1, 0, 15, 16, 31] as $x){
			foreach([World::Y_MIN, World::Y_MIN + 1, -1, 0, 1, World::Y_MAX - 1] as $y){
				foreach([-16, -1, 0, 7, 15, 16] as $z){
					$hash = World::chunkBlockHash($x, $y, $z);
					self::assertSame($x & Chunk::COORD_MASK, $hash & Chunk::COORD_MASK);
					self::assertSame($z & Chunk::COORD_MASK, ($hash >> Chunk::COORD_BIT_SIZE) & Chunk::COORD_MASK);
					self::assertSame($y, $hash >> (2 * Chunk::COORD_BIT_SIZE));
					$seen[$x & Chunk::COORD_MASK][$y][$z & Chunk::COORD_MASK][$hash] = true;
				}
			}
		}
		foreach($seen as $byY){
			foreach($byY as $byZ){
				foreach($byZ as $hashes){
					self::assertCount(1, $hashes);
				}
			}
		}
	}

	/**
	 * @return array<int, list<ClientboundPacket>> packets by chunk hash
	 */
	private function flushChangedBlocks() : array{
		return (function() : array{
			$result = [];
			foreach($this->changedBlocks as $index => $blocks){
				World::getXZ($index, $chunkX, $chunkZ);
				$result[$index] = $this->createChunkBlockUpdatePackets($chunkX, $chunkZ, $this->chunks[$index], $blocks);
			}
			$this->changedBlocks = [];
			return $result;
		})->call($this->world);
	}

	public function testChangedBlocksProduceOneUpdatePerPositionAcrossChunks() : void{
		$stone = VanillaBlocks::STONE();
		$dirt = VanillaBlocks::DIRT();
		$positions = [[-1, World::Y_MIN, -1], [0, World::Y_MAX - 1, 0], [15, -1, 16], [16, 0, 15], [-17, 5, 31]];
		foreach($positions as [$x, $y, $z]){
			$this->world->setBlockAt($x, $y, $z, $stone, false);
			$this->world->setBlockAt($x, $y, $z, $dirt, false);
			$this->world->setBlockAt($x, $y, $z, $stone, false);
		}

		$translator = TypeConverter::getInstance()->getBlockTranslator();
		$expected = [];
		foreach($positions as [$x, $y, $z]){
			$expected[World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)][] = "$x:$y:$z=" . $translator->internalIdToNetworkId($stone->getStateId());
		}
		$actual = array_map(fn(array $packets) : array => array_map(function(ClientboundPacket $packet) : string{
			self::assertInstanceOf(UpdateBlockPacket::class, $packet);
			$pos = $packet->blockPosition;
			return "{$pos->getX()}:{$pos->getY()}:{$pos->getZ()}=$packet->blockRuntimeId";
		}, $packets), $this->flushChangedBlocks());
		self::assertEquals($expected, $actual);
		self::assertSame([], $this->flushChangedBlocks());
	}

	public function testPublicBlockUpdatePacketsSkipUnloadedTerrain() : void{
		$this->world->setBlockAt(3, 64, 3, VanillaBlocks::STONE());
		$packets = $this->world->createBlockUpdatePackets([new Vector3(3, 64, 3), new Vector3(1000, 64, 1000)]);
		self::assertCount(1, $packets);
	}

	public function testTileBlockWritesStateAndTileOnce() : void{
		$this->world->setBlockAt(1, 64, 1, VanillaBlocks::CHEST());
		$chunk = $this->world->getChunk(0, 0) ?? self::fail();
		self::assertSame(VanillaBlocks::CHEST()->getStateId(), $chunk->getBlockStateId(1, 64, 1));
		self::assertInstanceOf(TileChest::class, $this->world->getTileAt(1, 64, 1));

		$packets = $this->flushChangedBlocks()[World::chunkHash(0, 0)];
		self::assertInstanceOf(UpdateBlockPacket::class, $packets[count($packets) - 2]);
		self::assertInstanceOf(BlockActorDataPacket::class, $packets[count($packets) - 1]);

		$this->world->setBlockAt(1, 64, 1, VanillaBlocks::STONE());
		self::assertNull($this->world->getTileAt(1, 64, 1));
		self::assertSame(VanillaBlocks::STONE()->getStateId(), $chunk->getBlockStateId(1, 64, 1));
	}

	public function testBlockCacheSizeTracksRealEntries() : void{
		for($i = 0; $i < 100; ++$i){
			$this->world->setBlockAt($i & 15, 64, 0, VanillaBlocks::STONE(), false);
		}
		self::assertSame(0, $this->blockCacheSize());

		$this->world->getBlockAt(2, 64, 0);
		$this->world->getBlockAt(-1, 64, -1);
		self::assertSame(2, $this->blockCacheSize());
		$this->world->setBlockAt(2, 64, 0, VanillaBlocks::DIRT(), false);
		$this->world->setBlockAt(2, 64, 0, VanillaBlocks::DIRT(), false);
		self::assertSame(1, $this->blockCacheSize());
		self::assertSame(VanillaBlocks::DIRT()->getStateId(), $this->world->getBlockAt(2, 64, 0)->getStateId());
		self::assertSame(2, $this->blockCacheSize());
	}

	private function blockCacheSize() : int{
		return (function() : int{
			$real = 0;
			foreach($this->blockCache as $blocks){
				$real += count($blocks);
			}
			TestCase::assertSame($real, $this->blockCacheSize);
			return $real;
		})->call($this->world);
	}

	private function hasCachedCollision(int $x, int $y, int $z) : bool{
		return (fn() : bool => isset($this->blockCollisionBoxCache[World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)][World::chunkBlockHash($x, $y, $z)]))->call($this->world);
	}

	public function testCollisionCacheInvalidatedAcrossChunkBoundary() : void{
		$above = new AxisAlignedBB(16.2, 65, 0.2, 16.8, 65.4, 0.8);
		self::assertSame([], $this->world->getBlockCollisionBoxes(new AxisAlignedBB(15.2, 64, 0.2, 16.8, 65.4, 0.8)));
		self::assertTrue($this->hasCachedCollision(15, 64, 0));

		//the fence reaches into the cell above, and its west neighbour sits in another chunk
		$this->world->setBlockAt(16, 64, 0, VanillaBlocks::OAK_FENCE(), false);
		self::assertFalse($this->hasCachedCollision(15, 64, 0));
		self::assertFalse($this->hasCachedCollision(16, 65, 0));
		self::assertNotSame([], $this->world->getBlockCollisionBoxes($above));

		$this->world->setBlockAt(16, 64, 0, VanillaBlocks::AIR(), false);
		self::assertSame([], $this->world->getBlockCollisionBoxes($above));
	}
}
