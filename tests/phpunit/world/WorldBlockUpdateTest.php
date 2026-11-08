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

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\UpdateBlockPacket;
use quark\block\tile\Chest as TileChest;
use quark\block\VanillaBlocks;
use quark\entity\Entity;
use quark\math\AxisAlignedBB;
use quark\math\Vector3;
use quark\network\mcpe\convert\TypeConverter;
use quark\player\Player;
use quark\world\format\Chunk;
use quark\world\redstone\RedstoneHarness;
use function array_map;
use function count;
use function spl_object_id;

final class WorldBlockUpdateTest extends TestCase{

	private World $world;
	private Player&MockObject $viewer;

	protected function setUp() : void{
		$this->world = (new RedstoneHarness(2))->world;
		$this->viewer = $this->createMock(Player::class);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($this->viewer, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($this->viewer, $this->createMock(\Logger::class));
		(function(Player $viewer) : void{
			foreach($this->chunks as $index => $_){
				$this->playerChunkListeners[$index][spl_object_id($viewer)] = $viewer;
			}
		})->call($this->world, $this->viewer);
	}

	public function testChunkBlockHashDecodesToLocalCoordinates() : void{
		foreach([-33, -17, -16, -1, 0, 15, 16, 31] as $x){
			foreach([World::Y_MIN, World::Y_MIN + 1, -1, 0, 1, World::Y_MAX - 1] as $y){
				foreach([-16, -1, 0, 7, 15, 16] as $z){
					$hash = World::chunkBlockHash($x, $y, $z);
					self::assertSame($x & Chunk::COORD_MASK, $hash & Chunk::COORD_MASK);
					self::assertSame($z & Chunk::COORD_MASK, ($hash >> Chunk::COORD_BIT_SIZE) & Chunk::COORD_MASK);
					self::assertSame($y, $hash >> (2 * Chunk::COORD_BIT_SIZE));
				}
			}
		}
	}

	/**
	 * Runs the end-of-tick block sync and returns what it buffered for each chunk.
	 *
	 * @return array<int, array<string>> chunk hash => "x:y:z=networkId" or packet class
	 */
	private function sendChangedBlocks() : array{
		(function() : void{
			$this->packetBuffersByChunk = [];
			$this->sendChangedBlocks();
		})->call($this->world);
		$buffers = (fn() : array => $this->packetBuffersByChunk)->call($this->world);
		return array_map(fn(array $packets) : array => array_map(function(object $packet) : string{
			if($packet instanceof UpdateBlockPacket){
				$pos = $packet->blockPosition;
				return "{$pos->getX()}:{$pos->getY()}:{$pos->getZ()}=$packet->blockRuntimeId";
			}
			return $packet::class;
		}, $packets), $buffers);
	}

	private function pendingChanges() : int{
		return (function() : int{
			$count = 0;
			foreach($this->changedBlocks as $blocks){
				$count += count($blocks);
			}
			return $count;
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

		$networkId = TypeConverter::getInstance()->getBlockTranslator()->internalIdToNetworkId($stone->getStateId());
		$expected = [];
		foreach($positions as [$x, $y, $z]){
			$expected[World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)][] = "$x:$y:$z=$networkId";
		}
		self::assertEquals($expected, $this->sendChangedBlocks());
		self::assertSame([], $this->sendChangedBlocks());
	}

	public function testChangesInUnwatchedChunksAreDropped() : void{
		(fn() => $this->playerChunkListeners = [])->call($this->world);
		$this->world->setBlockAt(3, 64, 3, VanillaBlocks::STONE(), false);
		self::assertSame([], $this->sendChangedBlocks());
		self::assertSame(0, $this->pendingChanges());
	}

	public function testLargeChangeResendsChunkAndKeepsChangesMadeMeanwhile() : void{
		$chunk = $this->world->getChunk(1, 1) ?? self::fail();
		$this->viewer->expects(self::once())->method("onChunkChanged")->with(1, 1, $chunk)->willReturnCallback(function() : void{
			//a chunk resend can lead to more changes, those must survive the reset of the change list
			$this->world->setBlockAt(16, 70, 16, VanillaBlocks::DIRT(), false);
			$this->world->unloadChunk(-1, -1, false, false);
		});
		for($i = 0; $i < 513; ++$i){
			$this->world->setBlockAt(16 + ($i & 15), 64 + ($i >> 8), 16 + (($i >> 4) & 15), VanillaBlocks::STONE(), false);
		}
		$this->world->setBlockAt(-1, 64, -1, VanillaBlocks::STONE(), false);

		self::assertSame([], $this->sendChangedBlocks());
		self::assertSame(1, $this->pendingChanges());
		self::assertCount(1, $this->sendChangedBlocks()[World::chunkHash(1, 1)]);
	}

	public function testPublicBlockUpdatePacketsSkipUnloadedTerrain() : void{
		$this->world->setBlockAt(3, 64, 3, VanillaBlocks::STONE());
		$packets = $this->world->createBlockUpdatePackets([new Vector3(3, 64, 3), new Vector3(1000, 64, 1000)]);
		self::assertCount(1, $packets);
	}

	public function testTileBlockWritesStateAndTile() : void{
		$this->world->setBlockAt(1, 64, 1, VanillaBlocks::CHEST());
		$chunk = $this->world->getChunk(0, 0) ?? self::fail();
		self::assertSame(VanillaBlocks::CHEST()->getStateId(), $chunk->getBlockStateId(1, 64, 1));
		self::assertInstanceOf(TileChest::class, $this->world->getTileAt(1, 64, 1));

		$packets = $this->sendChangedBlocks()[World::chunkHash(0, 0)];
		self::assertStringStartsWith("1:64:1=", $packets[count($packets) - 2]);
		self::assertSame(BlockActorDataPacket::class, $packets[count($packets) - 1]);

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
		$this->world->getBlockAt(2, 64, 0, false);
		$this->world->getBlockAt(2, 64, 0, false);
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
