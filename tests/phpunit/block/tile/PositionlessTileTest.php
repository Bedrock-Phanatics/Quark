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

namespace quark\block\tile;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;
use quark\block\VanillaBlocks;
use quark\item\VanillaItems;
use quark\math\Vector3;
use quark\Server;
use quark\utils\ReversePriorityQueue;
use quark\world\format\Chunk;
use quark\world\format\io\ChunkData;
use quark\world\format\io\WritableWorldProvider;
use quark\world\Position;
use quark\world\World;
use function gc_disable;
use function gc_enable;

final class PositionlessTileTest extends TestCase{

	private function world() : World{
		$world = (new \ReflectionClass(World::class))->newInstanceWithoutConstructor();
		$set = static fn(string $name, mixed $value) => (new \ReflectionProperty(World::class, $name))->setValue($world, $value);
		$server = $this->createMock(Server::class);
		$server->method("getTick")->willReturn(0);
		$set("server", $server);
		$set("minY", -64);
		$set("maxY", 320);
		$set("scheduledBlockUpdateQueue", new ReversePriorityQueue());
		$set("chunks", [World::chunkHash(0, 0) => new Chunk([], true)]);
		return $world;
	}

	public function testTileDataHasNoPosition() : void{
		$chest = new Chest();
		$chest->getInventory()->setItem(3, VanillaItems::DIAMOND()->setCount(5));
		self::assertFalse($chest->isAttached());

		$nbt = $chest->saveNBT();
		self::assertNull($nbt->getTag(Tile::TAG_X));
		self::assertNull($nbt->getTag(Tile::TAG_Z));

		$copy = TileFactory::getInstance()->createFromData($nbt);
		self::assertInstanceOf(Chest::class, $copy);
		self::assertFalse($copy->isAttached());
		self::assertTrue($copy->getInventory()->getItem(3)->equalsExact(VanillaItems::DIAMOND()->setCount(5)));

		$this->expectException(\LogicException::class);
		$chest->getPosition();
	}

	public function testWorldAttachesAndDetachesTiles() : void{
		$world = $this->world();
		$chunk = $world->getChunk(0, 0);
		self::assertNotNull($chunk);
		$tile = new Chest();

		$world->addTile($tile, new Vector3(1.7, 64, 2.2));
		$pos = $tile->getPosition();
		self::assertSame($world, $pos->getWorld());
		self::assertSame([1, 64, 2], [$pos->x, $pos->y, $pos->z]);
		self::assertSame($tile, $chunk->getTile(1, 64, 2));

		try{
			$world->addTile($tile, new Vector3(3, 64, 3));
			self::fail("an attached tile can't be added twice");
		}catch(\InvalidArgumentException){
		}
		try{
			$world->addTile(new Chest(), new Vector3(1, 64, 2));
			self::fail("two tiles can't share a position");
		}catch(\InvalidArgumentException){
		}

		$world->removeTile($tile);
		self::assertFalse($tile->isAttached());
		self::assertNull($chunk->getTile(1, 64, 2));
		self::assertFalse($tile->isClosed(), "removing keeps the tile usable");

		$world->addTile($tile, new Vector3(5, 70, 6));
		self::assertSame($tile, $chunk->getTile(5, 70, 6));
		$tile->close();
		self::assertFalse($tile->isAttached());
		self::assertSame([], $chunk->getTiles());
	}

	public function testSavedChunksStoreTileCoordinates() : void{
		$world = $this->world();
		$saved = [];
		$provider = $this->createMock(WritableWorldProvider::class);
		$provider->method("saveChunk")->willReturnCallback(function(int $x, int $z, ChunkData $data) use (&$saved) : void{
			$saved = $data->getTileNBT();
		});
		(new \ReflectionProperty(World::class, "provider"))->setValue($world, $provider);
		$world->addTile(new Chest(), new Vector3(4, -10, 9));

		$chunk = $world->getChunk(0, 0);
		self::assertNotNull($chunk);
		(new \ReflectionMethod(World::class, "saveChunk"))->invoke($world, 0, 0, World::chunkHash(0, 0), $chunk);
		self::assertCount(1, $saved);
		self::assertSame([4, -10, 9], [$saved[0]->getInt(Tile::TAG_X), $saved[0]->getInt(Tile::TAG_Y), $saved[0]->getInt(Tile::TAG_Z)]);
	}

	public function testChestDropsAPairThatIsNoLongerAdjacent() : void{
		$world = $this->createMock(World::class);
		$world->method("isLoaded")->willReturn(true);
		$nbt = CompoundTag::create()->setString(Tile::TAG_ID, "Chest")->setInt(Chest::TAG_PAIRX, 5)->setInt(Chest::TAG_PAIRZ, 0);

		$adjacent = TileFactory::getInstance()->createFromData($nbt);
		self::assertInstanceOf(Chest::class, $adjacent);
		$adjacent->attach(new Position(4, 64, 0, $world));
		self::assertTrue($adjacent->isPaired());

		$moved = TileFactory::getInstance()->createFromData($nbt);
		self::assertInstanceOf(Chest::class, $moved);
		$moved->attach(new Position(5, 64, 1, $world));
		self::assertTrue($moved->isPaired(), "pairs along z are adjacent too");

		$far = TileFactory::getInstance()->createFromData($nbt);
		self::assertInstanceOf(Chest::class, $far);
		$far->attach(new Position(6, 64, 1, $world));
		self::assertFalse($far->isPaired());
	}

	public function testFurnaceInventoryUpdatesOnlyTheAttachedPosition() : void{
		$world = $this->createMock(World::class);
		$world->method("isLoaded")->willReturn(true);
		$world->expects(self::once())->method("scheduleDelayedBlockUpdate")->with(self::callback(static fn(Vector3 $pos) => $pos->equals(new Vector3(2, 64, 3))), 1);

		$furnace = new NormalFurnace();
		$furnace->getInventory()->setItem(0, VanillaItems::RAW_IRON());
		$furnace->attach(new Position(2, 64, 3, $world));
		$furnace->getInventory()->setItem(1, VanillaItems::COAL());
		$furnace->detach();
		$furnace->getInventory()->setItem(1, VanillaItems::CHARCOAL());
	}

	public function testItemDataCannotPairAPlacedChestWithADistantOne() : void{
		$world = $this->createMock(World::class);
		$world->method("isLoaded")->willReturn(true);
		$chest = new Chest();
		$chest->attach(new Position(0, 64, 0, $world));

		$item = VanillaBlocks::CHEST()->asItem()->setCustomBlockData(CompoundTag::create()->setInt(Chest::TAG_PAIRX, 100)->setInt(Chest::TAG_PAIRZ, 100));
		$chest->copyDataFromItem($item);
		self::assertFalse($chest->isPaired());
	}

	public function testReattachedTilesSpawnAtTheirNewPosition() : void{
		$world = $this->world();
		$sign = new Sign();
		$world->addTile($sign, new Vector3(1, 64, 1));
		self::assertSame(1, $sign->getSerializedSpawnCompound()->getRoot()->getInt(Tile::TAG_X));

		$world->removeTile($sign);
		$world->addTile($sign, new Vector3(7, 64, 1));
		self::assertSame(7, $sign->getSerializedSpawnCompound()->getRoot()->getInt(Tile::TAG_X));
	}

	public function testInventoryListenersDoNotKeepTilesAlive() : void{
		//with the cycle collector off, only a missing reference cycle lets the tiles go
		gc_disable();
		try{
			$this->assertTilesAreFreed();
		}finally{
			gc_enable();
		}
	}

	private function assertTilesAreFreed() : void{
		foreach([
			static fn() => new NormalFurnace(),
			static fn() => new BrewingStand(),
			static fn() => new Campfire(),
		] as $create){
			$tile = $create();
			$class = $tile::class;
			$ref = \WeakReference::create($tile);
			unset($tile);
			self::assertNull($ref->get(), "$class is freed without the cycle collector");
		}
	}
}
