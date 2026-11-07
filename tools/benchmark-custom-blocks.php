<?php

declare(strict_types=1);

namespace quark\tools\benchmark_custom_blocks;

use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\world\format\PalettedBlockArray;
use quark\block\Block;
use quark\block\custom\BlockComponents;
use quark\block\custom\BlockDefinition;
use quark\block\custom\BlockRegistry;
use quark\block\custom\BlockState;
use quark\block\RuntimeBlockStateRegistry;
use quark\block\VanillaBlocks;
use quark\data\bedrock\BiomeIds;
use quark\inventory\CreativeInventory;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\serializer\ChunkSerializer;
use quark\plugin\Plugin;
use quark\world\format\Chunk;
use quark\world\format\io\GlobalBlockStateHandlers;
use quark\world\format\SubChunk;
use function array_map;
use function count;
use function dirname;
use function hrtime;
use function in_array;
use function ini_get;
use function json_encode;
use function memory_get_peak_usage;
use function memory_get_usage;
use function mt_rand;
use function mt_srand;
use function printf;
use function round;
use function sort;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const PHP_VERSION;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Requires development dependencies. Pass --vanilla-only to measure the same vanilla workloads on a build without
 * custom block support.
 */
final class CustomBlockBenchmark extends TestCase{
	/**
	 * @param \Closure() : mixed $action
	 * @return array{median_us: float, iterations: int, rounds: int}
	 */
	private static function measure(\Closure $action, int $iterations = 20000) : array{
		for($i = 0; $i < 1000; ++$i){ $action(); }
		$samples = [];
		for($round = 0; $round < 7; ++$round){
			$start = hrtime(true);
			for($i = 0; $i < $iterations; ++$i){ $action(); }
			$samples[] = (hrtime(true) - $start) / $iterations / 1000;
		}
		sort($samples);
		return ["median_us" => round($samples[3], 4), "iterations" => $iterations, "rounds" => 7];
	}

	/** @param list<int> $pool */
	private static function chunk(array $pool) : Chunk{
		mt_srand(7);
		$air = VanillaBlocks::AIR()->getStateId();
		$subChunks = [];
		for($y = -4; $y < 4; ++$y){
			$layer = new PalettedBlockArray($air);
			for($i = 0; $i < 4096; ++$i){
				$layer->set($i & 15, ($i >> 8) & 15, ($i >> 4) & 15, $pool[mt_rand(0, count($pool) - 1)]);
			}
			$subChunks[$y] = new SubChunk($air, $layer, null, new PalettedBlockArray(BiomeIds::PLAINS));
		}
		return new Chunk($subChunks, true);
	}

	/**
	 * @param list<Block> $blocks
	 * @return array<string, array{median_us: float, iterations: int, rounds: int}>
	 */
	private static function hotPaths(string $kind, array $blocks, TypeConverter $converter) : array{
		$runtime = RuntimeBlockStateRegistry::getInstance();
		$serializer = GlobalBlockStateHandlers::getSerializer();
		$deserializer = GlobalBlockStateHandlers::getDeserializer();
		$translator = $converter->getBlockTranslator();
		$ids = array_map(fn(Block $b) => $b->getStateId(), $blocks);
		$data = array_map(fn(int $id) => $serializer->serialize($id), $ids);
		$n = count($ids);
		$i = 0;
		$results = [];
		$results["$kind.from_state_id"] = self::measure(static function() use ($runtime, $ids, $n, &$i) : Block{ return $runtime->fromStateId($ids[++$i % $n]); });
		$results["$kind.collision_info"] = self::measure(static function() use ($runtime, $ids, $n, &$i) : int{ return $runtime->collisionInfo[$ids[++$i % $n]]; });
		$results["$kind.collision_boxes"] = self::measure(static function() use ($runtime, $ids, $n, &$i) : array{ return $runtime->fromStateId($ids[++$i % $n])->getCollisionBoxes(); });
		$results["$kind.serialize_block"] = self::measure(static function() use ($serializer, $blocks, $n, &$i) : mixed{ return $serializer->serializeBlock($blocks[++$i % $n]); });
		$results["$kind.deserialize"] = self::measure(static function() use ($deserializer, $data, $n, &$i) : int{ return $deserializer->deserialize($data[++$i % $n]); });
		$results["$kind.network_id"] = self::measure(static function() use ($translator, $ids, $n, &$i) : int{ return $translator->internalIdToNetworkId($ids[++$i % $n]); });
		$chunk = self::chunk($ids);
		$results["$kind.chunk_encode"] = self::measure(static fn() => ChunkSerializer::serializeFullChunk($chunk, DimensionIds::OVERWORLD, $translator), 300);
		return $results;
	}

	/** @param list<string> $argv */
	public function runBenchmark(array $argv) : void{
		$vanillaOnly = in_array("--vanilla-only", $argv, true);
		$vanilla = [VanillaBlocks::STONE(), VanillaBlocks::OAK_PLANKS(), VanillaBlocks::GLASS(), VanillaBlocks::STONE_BRICK_STAIRS(), VanillaBlocks::OAK_FENCE(), VanillaBlocks::OAK_SLAB(), VanillaBlocks::GLOWSTONE(), VanillaBlocks::OAK_DOOR()];
		$results = [];
		$memoryBefore = memory_get_usage();
		$custom = [];
		if(!$vanillaOnly){
			$plugin = $this->createMock(Plugin::class);
			$plugin->method("getName")->willReturn("Benchmark");
			$registry = BlockRegistry::getInstance();
			$visual = (new BlockComponents())->geometry("geometry.bench")->texture("bench");
			$start = hrtime(true);
			for($k = 0; $k < 200; ++$k){
				$registry->register(BlockDefinition::cube("bench:cube$k", "Cube $k", "cube$k"), $plugin);
			}
			for($k = 0; $k < 50; ++$k){
				$registry->register(BlockDefinition::cube("bench:lamp$k", "Lamp $k", "lamp")->states(BlockState::bool("bench:lit"), BlockState::int("bench:level", 0, 15), BlockState::enum("bench:tone", ["a", "b", "c", "d"]))->lightLevel(10), $plugin);
			}
			for($k = 0; $k < 20; ++$k){
				$registry->register(BlockDefinition::stairs("bench:stairs$k", "Stairs $k")->withComponents($visual), $plugin);
				$registry->register(BlockDefinition::fence("bench:fence$k", "Fence $k")->withComponents($visual), $plugin);
				$registry->register(BlockDefinition::door("bench:door$k", "Door $k")->withComponents($visual), $plugin);
			}
			$registered = hrtime(true);
			$converter = TypeConverter::getInstance();
			$registry->finalize($converter, CreativeInventory::getInstance());
			$finalized = hrtime(true);
			$results["registration.blocks"] = count($registry->getIdentifiers());
			$results["registration.ms"] = round(($registered - $start) / 1e6, 2);
			$results["finalize.ms"] = round(($finalized - $registered) / 1e6, 2);
			$results["registration.memory_bytes"] = memory_get_usage() - $memoryBefore;
			$results["palette.network_states"] = count($converter->getBlockTranslator()->getBlockStateDictionary()->getStates());
			$custom = [
				$registry->create("bench:cube1"), $registry->create("bench:cube2"), $registry->create("bench:lamp1"), $registry->create("bench:stairs1"),
				$registry->create("bench:fence1"), $registry->create("bench:lamp2"), $registry->create("bench:cube3"), $registry->create("bench:door1")
			];
		}
		$converter = TypeConverter::getInstance();
		$results += self::hotPaths("vanilla", $vanilla, $converter);
		if($custom !== []){
			$results += self::hotPaths("custom", $custom, $converter);
		}
		printf("%s\n", json_encode(["php" => PHP_VERSION, "assertions" => ini_get("zend.assertions"), "vanilla_only" => $vanillaOnly, "peak_bytes" => memory_get_peak_usage(true), "results" => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
	}
}

(new CustomBlockBenchmark("runBenchmark"))->runBenchmark($argv);
