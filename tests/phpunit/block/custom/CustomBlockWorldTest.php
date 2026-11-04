<?php

declare(strict_types=1);

namespace quark\block\custom;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\IntTag;
use pocketmine\world\format\PalettedBlockArray;
use quark\block\RuntimeBlockStateRegistry;
use quark\block\VanillaBlocks;
use quark\data\bedrock\BiomeIds;
use quark\data\bedrock\block\BlockStateData;
use quark\data\bedrock\item\BlockItemIdMap;
use quark\item\StringToItemParser;
use quark\plugin\Plugin;
use quark\utils\Filesystem;
use quark\world\format\Chunk;
use quark\world\format\io\ChunkData;
use quark\world\format\io\GlobalBlockStateHandlers;
use quark\world\format\io\leveldb\LevelDB;
use quark\world\format\SubChunk;
use quark\world\light\BlockLightUpdate;
use quark\world\light\LightPopulationTask;
use quark\world\light\SkyLightUpdate;
use quark\world\SimpleChunkManager;
use quark\world\utils\SubChunkExplorer;
use quark\world\World;
use quark\world\WorldCreationOptions;
use Symfony\Component\Filesystem\Path;
use function bin2hex;
use function count;
use function mt_rand;
use function mt_srand;
use function random_bytes;
use function sys_get_temp_dir;

/**
 * World providers use the global block handlers, so these blocks live in the global registries.
 */
final class CustomBlockWorldTest extends TestCase{
	private static int $run = 0;
	private string $path;
	private BlockRegistry $registry;
	private Plugin $owner;
	private string $ns;

	private ?RuntimeBlockStateRegistry $globalRuntime = null;

	protected function setUp() : void{
		$this->globalRuntime = RuntimeBlockStateRegistry::getInstance();
		RuntimeBlockStateRegistry::setInstance(new RuntimeBlockStateRegistry());
		$this->path = Path::join(sys_get_temp_dir(), "quark-customblocks-" . bin2hex(random_bytes(6)));
		LevelDB::generate($this->path, "test", WorldCreationOptions::create());
		$this->registry = new BlockRegistry(RuntimeBlockStateRegistry::getInstance(), GlobalBlockStateHandlers::getSerializer(), GlobalBlockStateHandlers::getDeserializer(), new StringToItemParser(), new BlockItemIdMap([]));
		$this->owner = $this->createMock(Plugin::class);
		$this->owner->method("getName")->willReturn("WorldTest");
		$this->ns = "world" . ++self::$run;
	}

	protected function tearDown() : void{
		if($this->globalRuntime !== null){
			RuntimeBlockStateRegistry::setInstance($this->globalRuntime);
		}
		Filesystem::recursiveUnlink($this->path);
	}

	private function open() : LevelDB{
		return new LevelDB($this->path, new \PrefixedLogger(\GlobalLogger::get(), "CustomBlockWorldTest"));
	}

	/** @param list<int> $states */
	private static function chunk(array $states) : ChunkData{
		$chunk = new Chunk([], true);
		foreach($states as $i => $state){
			$chunk->setBlockStateId($i % 16, 5 + ($i >> 4), 3, $state);
		}
		return new ChunkData($chunk->getSubChunks(), true, [], []);
	}

	/** @return list<int> */
	private static function load(LevelDB $provider) : array{
		$loaded = $provider->loadChunk(0, 0);
		self::assertNotNull($loaded);
		$chunk = new Chunk($loaded->getData()->getSubChunks(), true);
		$states = [];
		for($i = 0; $i < 32; ++$i){
			$states[] = $chunk->getBlockStateId($i % 16, 5 + ($i >> 4), 3);
		}
		return $states;
	}

	public function testCustomStatesSurviveSaveAndReload() : void{
		$this->registry->register(BlockDefinition::cube("$this->ns:ore", "Ore", "ore")->states(BlockState::int("$this->ns:richness", 0, 3)), $this->owner);
		$this->registry->register(BlockDefinition::slab("$this->ns:slab", "Ore")->withComponents((new BlockComponents())->geometry("geometry.slab")->texture("ore")), $this->owner);
		$states = [];
		foreach(["ore", "slab"] as $path){
			foreach($this->registry->create("$this->ns:$path")->generateStatePermutations() as $state){
				$states[] = $state->getStateId();
			}
		}
		$states = [...$states, ...[VanillaBlocks::STONE()->getStateId(), VanillaBlocks::DIRT()->getStateId()]];
		while(count($states) < 32){
			$states[] = VanillaBlocks::AIR()->getStateId();
		}
		$provider = $this->open();
		$provider->saveChunk(0, 0, self::chunk($states), Chunk::DIRTY_FLAGS_ALL);
		$provider->close();

		for($cycle = 0; $cycle < 3; ++$cycle){
			$provider = $this->open();
			self::assertSame($states, self::load($provider));
			$provider->close();
		}
	}

	public function testMissingBlockIsKeptOnDiskAndRestoredWhenRegistered() : void{
		$saved = BlockStateData::current("$this->ns:gem", ["$this->ns:cut" => new IntTag(2)]);
		$placeholder = GlobalBlockStateHandlers::getDeserializer()->deserialize($saved);
		self::assertInstanceOf(UnknownCustomBlock::class, RuntimeBlockStateRegistry::getInstance()->fromStateId($placeholder));
		$states = [$placeholder, VanillaBlocks::STONE()->getStateId()];
		while(count($states) < 32){
			$states[] = VanillaBlocks::AIR()->getStateId();
		}
		$provider = $this->open();
		$provider->saveChunk(0, 0, self::chunk($states), Chunk::DIRTY_FLAGS_ALL);
		$provider->close();

		//load and save again while the plugin is still missing
		$known = count(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates());
		for($cycle = 0; $cycle < 5; ++$cycle){
			$provider = $this->open();
			$loaded = self::load($provider);
			self::assertSame($placeholder, $loaded[0]);
			$provider->saveChunk(0, 0, self::chunk($loaded), Chunk::DIRTY_FLAGS_ALL);
			$provider->close();
		}
		self::assertCount($known, RuntimeBlockStateRegistry::getInstance()->getAllKnownStates(), "reloading does not allocate new placeholders");

		$this->registry->register(BlockDefinition::cube("$this->ns:gem", "Gem", "gem")->states(BlockState::int("$this->ns:cut", 0, 3)), $this->owner);
		$provider = $this->open();
		$restored = RuntimeBlockStateRegistry::getInstance()->fromStateId(self::load($provider)[0]);
		$provider->close();
		self::assertInstanceOf(CustomBlock::class, $restored);
		self::assertSame(2, $restored->getState("$this->ns:cut"));
	}

	public function testNativeLightPopulationSeesCustomLight() : void{
		$this->registry->register(BlockDefinition::cube("$this->ns:glow", "Glow", "glow")->lightLevel(13), $this->owner);
		$this->registry->register(BlockDefinition::transparent("$this->ns:glass", "Glass", "glass"), $this->owner);
		$this->registry->register(BlockDefinition::cube("$this->ns:wall", "Wall", "wall"), $this->owner);
		$pool = [
			VanillaBlocks::AIR()->getStateId(),
			$this->registry->create("$this->ns:glow")->getStateId(),
			$this->registry->create("$this->ns:glass")->getStateId(),
			$this->registry->create("$this->ns:wall")->getStateId(),
		];
		$make = static function() use ($pool) : Chunk{
			mt_srand(42);
			$air = $pool[0];
			$subChunks = [];
			foreach([0, 1, 2] as $y){
				$layer = new PalettedBlockArray($air);
				for($i = 0; $i < 900; ++$i){
					$layer->set(mt_rand(0, 15), mt_rand(0, 15), mt_rand(0, 15), $pool[mt_rand(0, 3)]);
				}
				$subChunks[$y] = new SubChunk($air, [$layer], new PalettedBlockArray(BiomeIds::PLAINS));
			}
			return new Chunk($subChunks, true);
		};
		$registry = RuntimeBlockStateRegistry::getInstance();
		$expected = $make();
		$manager = new SimpleChunkManager(World::Y_MIN, World::Y_MAX);
		$manager->setChunk(0, 0, $expected);
		foreach([
			new BlockLightUpdate(new SubChunkExplorer($manager), $registry->lightFilter, $registry->light),
			new SkyLightUpdate(new SubChunkExplorer($manager), $registry->lightFilter, $registry->blocksDirectSkyLight),
		] as $update){
			$update->recalculateChunk(0, 0);
			$update->execute();
		}
		$result = null;
		$task = new LightPopulationTask($make(), function(array $blockLight, array $skyLight) use (&$result) : void{
			$result = [$blockLight, $skyLight];
		});
		$task->onRun();
		$task->onCompletion();
		self::assertNotNull($result);
		foreach($expected->getSubChunks() as $y => $subChunk){
			self::assertSame($subChunk->getBlockLightArray()->getData(), $result[0][$y]->getData(), "block light in subchunk $y");
			self::assertSame($subChunk->getBlockSkyLightArray()->getData(), $result[1][$y]->getData(), "sky light in subchunk $y");
		}
		self::assertSame(13, $registry->light[$pool[1]]);
		self::assertSame(1, $registry->lightFilter[$pool[2]], "glass only applies the base filter");
	}
}
