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

namespace quark\world\metadata;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;
use quark\block\utils\DyeColor;
use quark\block\utils\redstone\RedstoneBlockUtils;
use quark\block\VanillaBlocks;
use quark\math\Vector3;
use quark\plugin\Plugin;
use quark\world\format\Chunk;
use quark\world\format\io\ChunkData;
use quark\world\format\io\exception\UnsupportedWorldFormatException;
use quark\world\format\io\leveldb\ChunkDataKey;
use quark\world\format\io\leveldb\LevelDB;
use quark\world\format\io\MetadataWorldProvider;
use quark\world\format\io\WritableWorldProvider;
use quark\world\format\io\zigrite\ZigriteDB;
use quark\world\World;
use quark\world\WorldCreationOptions;
use quark\world\WorldException;
use Symfony\Component\Filesystem\Filesystem;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;

final class WorldMetadataTest extends TestCase{

	private string $path;
	/** @phpstan-var \Closure(string) : MetadataWorldProvider */
	private \Closure $open;
	/** @var list<MetadataWorld> */
	private array $open_worlds = [];

	/** @var MetadataKey<string> */
	private static MetadataKey $owner;
	/** @var MetadataKey<int> */
	private static MetadataKey $level;
	/** @var MetadataKey<int> */
	private static MetadataKey $color;
	/** @var MetadataKey<string> */
	private static MetadataKey $claim;
	/** @var MetadataKey<string> */
	private static MetadataKey $serial;
	/** @var MetadataKey<string> */
	private static MetadataKey $other;

	protected function setUp() : void{
		$this->path = sys_get_temp_dir() . "/quark-metadata-" . uniqid();
		$plugin = $this->plugin("MetaTest");
		self::$owner = MetadataKey::create($plugin, "owner", MetadataCodec::string(), Retention::BLOCK_TYPE);
		self::$level = MetadataKey::create($plugin, "level", MetadataCodec::int(), Retention::BLOCK_TYPE);
		self::$color = MetadataKey::create($plugin, "color", MetadataCodec::int(), Retention::BLOCK_STATE);
		self::$claim = MetadataKey::create($plugin, "claim", MetadataCodec::string(), Retention::POSITION);
		self::$serial = MetadataKey::create($plugin, "serial", MetadataCodec::string(), Retention::BLOCK_TYPE, copyable: false);
		self::$other = MetadataKey::create($this->plugin("Other Plugin"), "owner", MetadataCodec::string(), Retention::BLOCK_TYPE);
	}

	private function plugin(string $name) : Plugin{
		$plugin = self::createStub(Plugin::class);
		$plugin->method("getName")->willReturn($name);
		return $plugin;
	}

	protected function tearDown() : void{
		foreach($this->open_worlds as $world){
			if($world->world->isLoaded()){
				$world->close();
			}
		}
		(new Filesystem())->remove($this->path);
	}

	/**
	 * @return \Generator<string, array{string}>
	 */
	public static function formats() : \Generator{
		yield "leveldb" => ["leveldb"];
		yield "zigritedb" => ["zigritedb"];
	}

	private function create(string $format) : MetadataWorld{
		try{
			if($format === "leveldb"){
				LevelDB::generate($this->path, "Metadata Test", WorldCreationOptions::create());
				$this->open = fn(string $path) => new LevelDB($path, \GlobalLogger::get());
			}else{
				ZigriteDB::generate($this->path, "Metadata Test", WorldCreationOptions::create());
				$this->open = fn(string $path) => new ZigriteDB($path, \GlobalLogger::get());
			}
		}catch(UnsupportedWorldFormatException $e){
			self::markTestSkipped($e->getMessage());
		}
		return $this->reopen();
	}

	/**
	 * @phpstan-param (\Closure(MetadataWorldProvider) : MetadataWorldProvider)|null $wrap
	 */
	private function reopen(?\Closure $wrap = null) : MetadataWorld{
		$provider = ($this->open)($this->path);
		$world = new MetadataWorld($wrap !== null ? $wrap($provider) : $provider);
		$this->open_worlds[] = $world;
		return $world;
	}

	/**
	 * @phpstan-param (\Closure(MetadataWorldProvider) : MetadataWorldProvider)|null $wrap
	 */
	private function restart(MetadataWorld $world, ?\Closure $wrap = null) : MetadataWorld{
		$world->close();
		return $this->reopen($wrap);
	}

	/**
	 * @dataProvider formats
	 */
	public function testValuesSurviveUnloadAndRestart(string $format) : void{
		$world = $this->create($format);
		$world->generate(0, 0);
		$world->generate(-1, -1);
		$metadata = $world->world->getMetadata();

		$metadata->setBlock(1, 64, 1, self::$owner, "alice");
		$metadata->setBlock(1, 64, 1, self::$level, 3);
		$metadata->setBlock(1, 64, 1, self::$other, "not alice");
		$metadata->setBlock(-1, -64, -1, self::$owner, "\x00binary\xff");
		$metadata->setBlock(-16, 319, -16, self::$level, -42);
		$metadata->setChunk(0, 0, self::$owner, "chunk owner");
		$metadata->set(self::$owner, "world owner");
		$tag = CompoundTag::create()->setString("a", "b")->setInt("c", 5);
		$compound = MetadataKey::create($this->plugin("MetaTest"), "compound", MetadataCodec::compound(), Retention::POSITION);
		$metadata->setBlock(2, 2, 2, $compound, $tag);

		$world->world->save(true);
		self::assertTrue($world->world->unloadChunk(0, 0, false));
		self::assertSame("alice", $metadata->getBlock(1, 64, 1, self::$owner), "an unloaded chunk loads again for a read");

		$world = $this->restart($world);
		$metadata = $world->world->getMetadata();
		self::assertSame("alice", $metadata->getBlock(1, 64, 1, self::$owner));
		self::assertSame(3, $metadata->getBlock(1, 64, 1, self::$level));
		self::assertSame("not alice", $metadata->getBlock(1, 64, 1, self::$other), "namespaces don't overwrite each other");
		self::assertSame("\x00binary\xff", $metadata->getBlock(-1, -64, -1, self::$owner));
		self::assertSame(-42, $metadata->getBlock(-16, 319, -16, self::$level));
		self::assertSame("chunk owner", $metadata->getChunk(0, 0, self::$owner));
		self::assertSame("world owner", $metadata->get(self::$owner));
		$stored = $metadata->getBlock(2, 2, 2, $compound);
		self::assertNotNull($stored);
		self::assertTrue($tag->equals($stored));
		self::assertNull($metadata->getBlock(1, 65, 1, self::$owner));
		self::assertNull($metadata->getBlock(500, 0, 500, self::$owner), "ungenerated terrain has nothing");
		self::assertSame([[1, 64, 1], [2, 2, 2]], $metadata->getBlocks(0, 0));

		self::assertTrue($metadata->removeBlock(1, 64, 1, self::$owner));
		self::assertFalse($metadata->removeBlock(1, 64, 1, self::$owner));
		$metadata->setBlock(1, 64, 1, self::$level, 4);
		self::assertTrue($metadata->remove(self::$owner));
		$world = $this->restart($world);
		$metadata = $world->world->getMetadata();
		self::assertNull($metadata->getBlock(1, 64, 1, self::$owner));
		self::assertSame(4, $metadata->getBlock(1, 64, 1, self::$level));
		self::assertNull($metadata->get(self::$owner));

		$this->expectException(WorldException::class);
		$metadata->setBlock(500, 0, 500, self::$owner, "x");
	}

	/**
	 * @dataProvider formats
	 */
	public function testMetadataOnlyChangesAreSavedOnceAndNothingIsWrittenWithoutThem(string $format) : void{
		$faulty = null;
		$world = $this->create($format);
		$world->generate(0, 0);
		$world = $this->restart($world, function(MetadataWorldProvider $provider) use (&$faulty) : FaultyProvider{
			return $faulty = new FaultyProvider($provider);
		});
		self::assertInstanceOf(FaultyProvider::class, $faulty);
		self::assertNull($world->world->getMetadataIfUsed(), "a world without metadata doesn't set it up");
		self::assertNull($faulty->loadWorldMetadata());

		self::assertNotNull($world->world->loadChunk(0, 0));
		$world->world->save(true);
		self::assertSame([], $faulty->chunkSaves, "a clean chunk is not written");

		$world->world->getMetadata()->setBlock(0, 0, 0, self::$owner, "alice");
		$world->world->save(true);
		self::assertCount(1, $faulty->chunkSaves);
		[, , $flags] = $faulty->chunkSaves[0];
		self::assertSame(Chunk::DIRTY_FLAG_METADATA, $flags, "only metadata is written for a metadata change");
		$world->world->save(true);
		self::assertCount(1, $faulty->chunkSaves, "saved metadata is clean");

		$world->world->getMetadata()->setBlock(0, 0, 0, self::$owner, "alice");
		$world->world->save(true);
		self::assertCount(1, $faulty->chunkSaves, "setting the same value changes nothing");
	}

	/**
	 * @dataProvider formats
	 */
	public function testFailedSavesKeepChangesForTheRetry(string $format) : void{
		$faulty = null;
		$world = $this->create($format);
		$world->generate(0, 0);
		$world = $this->restart($world, function(MetadataWorldProvider $provider) use (&$faulty) : FaultyProvider{
			return $faulty = new FaultyProvider($provider);
		});
		self::assertInstanceOf(FaultyProvider::class, $faulty);
		$metadata = $world->world->getMetadata();
		$metadata->setBlock(0, 0, 0, self::$owner, "alice");
		$metadata->set(self::$owner, "world");

		$faulty->failChunkSaves = 1;
		$faulty->failWorldSaves = 1;
		try{
			$world->world->save(true);
			self::fail("the failure should surface");
		}catch(\RuntimeException){
		}

		$faulty->failChunkSaves = 1;
		try{
			$world->world->unloadChunk(0, 0, false);
			self::fail("the failure should surface");
		}catch(\RuntimeException){
		}
		self::assertTrue($world->world->isChunkLoaded(0, 0), "a chunk that failed to save stays loaded");

		$world = $this->restart($world);
		$metadata = $world->world->getMetadata();
		self::assertSame("alice", $metadata->getBlock(0, 0, 0, self::$owner));
		self::assertSame("world", $metadata->get(self::$owner));
	}

	/**
	 * @dataProvider formats
	 */
	public function testRetentionFollowsBlockChanges(string $format) : void{
		$world = $this->create($format);
		$world->generate(0, 0);
		$w = $world->world;
		$metadata = $w->getMetadata();
		$wool = VanillaBlocks::WOOL()->setColor(DyeColor::RED);
		$w->setBlockAt(1, 1, 1, $wool, false);
		foreach([self::$owner, self::$claim] as $key){
			$metadata->setBlock(1, 1, 1, $key, "v");
		}
		$metadata->setBlock(1, 1, 1, self::$color, 1);

		$w->setBlockAt(1, 1, 1, clone $wool, false);
		self::assertSame(1, $metadata->getBlock(1, 1, 1, self::$color), "rewriting the same state is no change");

		$w->setBlockAt(1, 1, 1, (clone $wool)->setColor(DyeColor::BLUE), false);
		self::assertNull($metadata->getBlock(1, 1, 1, self::$color), "a state change drops state-bound values");
		self::assertSame("v", $metadata->getBlock(1, 1, 1, self::$owner), "and keeps type-bound ones");

		$w->setBlockAt(1, 1, 1, VanillaBlocks::AIR(), false);
		self::assertNull($metadata->getBlock(1, 1, 1, self::$owner), "breaking the block drops type-bound values");
		self::assertSame("v", $metadata->getBlock(1, 1, 1, self::$claim), "position-bound values stay");

		$w->setBlockAt(1, 1, 1, clone $wool, false);
		self::assertNull($metadata->getBlock(1, 1, 1, self::$owner), "a new block of the old type doesn't inherit anything");

		$metadata->setBlock(2, 2, 2, self::$owner, "kept");
		$metadata->setBlock(3, 3, 3, self::$owner, "dropped");
		$w->setBlockAt(3, 3, 3, VanillaBlocks::STONE(), false);
		$metadata->setBlock(3, 3, 3, self::$owner, "dropped");
		$replacement = new Chunk([], true);
		$replacement->setBlockStateId(3, 3, 3, VanillaBlocks::DIRT()->getStateId());
		$w->setChunk(0, 0, $replacement);
		self::assertSame("kept", $metadata->getBlock(2, 2, 2, self::$owner), "chunk replacement keeps values of unchanged blocks");
		self::assertNull($metadata->getBlock(3, 3, 3, self::$owner), "and applies retention to changed ones");
		self::assertSame("v", $metadata->getBlock(1, 1, 1, self::$claim));

		$world = $this->restart($world);
		self::assertNull($world->world->getMetadata()->getBlock(3, 3, 3, self::$owner), "the replacement is what got stored");
		self::assertSame("kept", $world->world->getMetadata()->getBlock(2, 2, 2, self::$owner));
	}

	/**
	 * @dataProvider formats
	 */
	public function testMovesAndCopies(string $format) : void{
		$world = $this->create($format);
		$world->generate(0, 0);
		$w = $world->world;
		$metadata = $w->getMetadata();
		$w->setBlockAt(1, 1, 1, VanillaBlocks::STONE(), false);
		$metadata->setBlock(1, 1, 1, self::$owner, "alice");
		$metadata->setBlock(1, 1, 1, self::$serial, "id-1");
		$metadata->setBlock(1, 1, 1, self::$claim, "claim");

		$copy = $metadata->copyBlock(1, 1, 1);
		self::assertNotNull($copy);
		self::assertSame(["metatest:owner"], $copy->getNames(), "unique and position-bound values aren't copied");
		self::assertSame("alice", $copy->get(self::$owner));
		$w->setBlockAt(5, 1, 5, VanillaBlocks::STONE(), false);
		$metadata->putBlock(5, 1, 5, $copy);
		self::assertSame("alice", $metadata->getBlock(5, 1, 5, self::$owner));
		self::assertNull($metadata->getBlock(5, 1, 5, self::$serial));

		RedstoneBlockUtils::moveBlockAndTile($w, new Vector3(1, 1, 1), new Vector3(1, 2, 1), false);
		self::assertSame(VanillaBlocks::STONE()->getStateId(), $w->getBlockAt(1, 2, 1)->getStateId());
		self::assertSame("alice", $metadata->getBlock(1, 2, 1, self::$owner), "a moved block keeps its values");
		self::assertSame("id-1", $metadata->getBlock(1, 2, 1, self::$serial), "including unique ones");
		self::assertNull($metadata->getBlock(1, 1, 1, self::$owner));
		self::assertSame("claim", $metadata->getBlock(1, 1, 1, self::$claim), "position-bound values stay behind");
		self::assertNull($metadata->getBlock(1, 2, 1, self::$claim));

		$first = $this->path;
		$this->path .= "-other";
		try{
			$other = $this->create($format);
			$other->generate(0, 0);
			self::assertNull($other->world->getMetadata()->getBlock(1, 2, 1, self::$owner), "worlds don't share metadata");
			$other->world->getMetadata()->putBlock(1, 2, 1, $copy);
			self::assertSame("alice", $other->world->getMetadata()->getBlock(1, 2, 1, self::$owner), "snapshots move between worlds");
			$other->close();
		}finally{
			(new Filesystem())->remove($this->path);
			$this->path = $first;
		}
	}

	/**
	 * @dataProvider formats
	 */
	public function testRemoveNamespace(string $format) : void{
		$world = $this->create($format);
		$world->generate(0, 0);
		$world->generate(10, 10);
		$metadata = $world->world->getMetadata();
		$metadata->setBlock(0, 0, 0, self::$owner, "loaded");
		$metadata->setBlock(160, 0, 160, self::$owner, "unloaded");
		$metadata->setBlock(160, 0, 160, self::$other, "other");
		$metadata->set(self::$owner, "world");
		$world->world->save(true);
		self::assertTrue($world->world->unloadChunk(10, 10, false));

		$metadata->removeNamespace("metatest");
		self::assertNull($metadata->getBlock(0, 0, 0, self::$owner));
		self::assertNull($metadata->get(self::$owner));
		$metadata->setBlock(0, 0, 1, self::$owner, "after");

		$world = $this->restart($world);
		$metadata = $world->world->getMetadata();
		self::assertNull($metadata->getBlock(160, 0, 160, self::$owner), "an unloaded chunk loses the values when it loads");
		self::assertSame("other", $metadata->getBlock(160, 0, 160, self::$other));
		self::assertSame("after", $metadata->getBlock(0, 0, 1, self::$owner), "values set after the removal stay");
		self::assertNull($metadata->get(self::$owner));

		$metadata->setBlock(160, 0, 161, self::$owner, "new");
		$world = $this->restart($world);
		self::assertSame("new", $world->world->getMetadata()->getBlock(160, 0, 161, self::$owner));
		self::assertNull($world->world->getMetadata()->getBlock(160, 0, 160, self::$owner));
	}

	/**
	 * @dataProvider formats
	 */
	public function testFailedNamespaceRemovalChangesNothing(string $format) : void{
		$faulty = null;
		$world = $this->create($format);
		$world->generate(0, 0);
		$world = $this->restart($world, function(MetadataWorldProvider $provider) use (&$faulty) : FaultyProvider{
			return $faulty = new FaultyProvider($provider);
		});
		self::assertInstanceOf(FaultyProvider::class, $faulty);
		$metadata = $world->world->getMetadata();
		$metadata->setBlock(0, 0, 0, self::$owner, "alice");
		$metadata->set(self::$owner, "world");
		$faulty->failWorldSaves = 1;
		try{
			$metadata->removeNamespace("metatest");
			self::fail("the failure should surface");
		}catch(\RuntimeException){
		}
		self::assertSame("alice", $metadata->getBlock(0, 0, 0, self::$owner));
		self::assertSame("world", $metadata->get(self::$owner));
		$world = $this->restart($world);
		self::assertSame("alice", $world->world->getMetadata()->getBlock(0, 0, 0, self::$owner));
	}

	public function testUnreadableChunkMetadataIsKeptAsStored() : void{
		$world = $this->create("leveldb");
		$world->generate(0, 0);
		$world->world->getMetadata()->setBlock(0, 0, 0, self::$owner, "alice");
		$world->close();

		$db = new LevelDB($this->path, \GlobalLogger::get());
		$key = LevelDB::chunkIndex(0, 0) . ChunkDataKey::QUARK_METADATA;
		$corrupt = "garbage that is not a metadata record";
		$db->getDatabase()->put($key, $corrupt);
		$db->close();

		$world = $this->reopen();
		$w = $world->world;
		self::assertNotNull($w->loadChunk(0, 0));
		try{
			$w->getMetadata()->getBlock(0, 0, 0, self::$owner);
			self::fail("unreadable metadata should not read as missing");
		}catch(MetadataException){
		}
		try{
			$w->getMetadata()->setBlock(0, 0, 0, self::$owner, "mallory");
			self::fail("unreadable metadata should not be overwritten");
		}catch(MetadataException){
		}
		$w->setBlockAt(0, 0, 0, VanillaBlocks::STONE(), false);
		$w->setChunk(0, 0, new Chunk([], true));
		$world->close();

		$db = new LevelDB($this->path, \GlobalLogger::get());
		self::assertSame($corrupt, $db->getDatabase()->get($key), "the stored bytes survive terrain rewrites");
		$db->close();

		$world = $this->reopen();
		$world->world->getMetadata()->clearChunk(0, 0);
		$world->world->getMetadata()->setBlock(0, 0, 0, self::$owner, "fresh");
		$world = $this->restart($world);
		self::assertSame("fresh", $world->world->getMetadata()->getBlock(0, 0, 0, self::$owner), "clearing is the explicit way out");
	}

	public function testUnreadableWorldRecordDisablesMetadataWithoutLosingIt() : void{
		$world = $this->create("leveldb");
		$world->generate(0, 0);
		$world->world->getMetadata()->setBlock(0, 0, 0, self::$owner, "alice");
		$world->close();

		$db = new LevelDB($this->path, \GlobalLogger::get());
		$db->saveWorldMetadata("broken");
		$record = $db->getDatabase()->get(LevelDB::chunkIndex(0, 0) . ChunkDataKey::QUARK_METADATA);
		$db->close();

		$world = $this->reopen();
		try{
			$world->world->getMetadata()->getBlock(0, 0, 0, self::$owner);
			self::fail("metadata should be unavailable");
		}catch(MetadataException){
		}
		$world->world->setChunk(0, 0, new Chunk([], true));
		$world->close();

		$db = new LevelDB($this->path, \GlobalLogger::get());
		self::assertSame("broken", $db->loadWorldMetadata());
		self::assertSame($record, $db->getDatabase()->get(LevelDB::chunkIndex(0, 0) . ChunkDataKey::QUARK_METADATA));
		$db->close();
	}

	public function testUnusedMetadataWritesNothing() : void{
		$world = $this->create("leveldb");
		$world->generate(0, 0);
		$world->world->setBlockAt(0, 0, 0, VanillaBlocks::STONE(), false);
		$world->close();
		$db = new LevelDB($this->path, \GlobalLogger::get());
		foreach($db->getDatabase()->getIterator() as $key => $_){
			self::assertStringEndsNotWith(ChunkDataKey::QUARK_METADATA, $key);
		}
		self::assertNull($db->loadWorldMetadata());
		$db->close();
	}

	/**
	 * @dataProvider formats
	 */
	public function testTerrainRewritesLeaveMetadataAlone(string $format) : void{
		$world = $this->create($format);
		$world->generate(0, 0);
		$world->world->getMetadata()->setBlock(0, 0, 0, self::$owner, "alice");
		$world->close();
		$provider = ($this->open)($this->path);
		$data = $provider->loadChunk(0, 0)?->getData();
		self::assertNotNull($data);
		$provider->saveChunk(0, 0, new ChunkData($data->getSubChunks(), true, [], []), Chunk::DIRTY_FLAGS_ALL);
		self::assertSame($data->getMetadata(), $provider->loadChunk(0, 0)?->getData()->getMetadata(), "a full terrain write is not a metadata write");
		$provider->close();
		$world = $this->reopen();
		self::assertSame("alice", $world->world->getMetadata()->getBlock(0, 0, 0, self::$owner));
	}

	public function testChunksKeptLoadedPastUnloadAreNotUsable() : void{
		$world = $this->create("leveldb");
		$world->generate(0, 0);
		$metadata = $world->world->getMetadata();
		$metadata->setBlock(0, 0, 0, self::$owner, "alice");
		$metadata->close();
		(new \ReflectionProperty(World::class, "unloaded"))->setValue($world->world, true);
		try{
			$metadata->setBlock(0, 0, 0, self::$owner, "lost");
			self::fail("a write to an unloaded world was accepted");
		}catch(WorldException){
			$this->addToAssertionCount(1);
		}
		(new \ReflectionProperty(World::class, "unloaded"))->setValue($world->world, false);
	}

	public function testFormatsWithoutMetadataRefuseIt() : void{
		$world = new MetadataWorld($this->createMock(WritableWorldProvider::class));
		$this->expectException(WorldException::class);
		$world->world->getMetadata();
	}

	public function testUnloadedWorldsRefuseAccess() : void{
		$world = $this->create("leveldb");
		$metadata = $world->world->getMetadata();
		$world->close();
		$this->expectException(WorldException::class);
		$metadata->get(self::$owner);
	}

	public function testKeys() : void{
		self::assertSame("metatest:owner", self::$owner->name);
		self::assertSame("other_plugin:owner", self::$other->name);
		foreach(["", "Upper", "a b", "x:y", str_repeat("a", 65)] as $path){
			try{
				MetadataKey::create($this->plugin("MetaTest"), $path, MetadataCodec::string(), Retention::POSITION);
				self::fail("path \"$path\" was accepted");
			}catch(\InvalidArgumentException){
			}
		}
		foreach(["Quark", "other_plugin", str_repeat("a", 129)] as $name){
			try{
				MetadataKey::create($this->plugin($name), "a", MetadataCodec::string(), Retention::POSITION);
				self::fail("namespace of \"$name\" was given out");
			}catch(\InvalidArgumentException){
			}
		}
		$this->expectException(\TypeError::class);
		$world = $this->create("leveldb");
		$world->generate(0, 0);
		$world->world->getMetadata()->setBlock(0, 0, 0, self::$level, "not an int"); //@phpstan-ignore argument.type
	}
}
