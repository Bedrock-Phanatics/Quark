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

namespace quark\tools\benchmark_nbt;

use Bedwire\Nbt as NativeNbt;
use pmmp\encoding\LE;
use pocketmine\nbt\BaseNbtSerializer;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\TreeRoot;
use quark\data\bedrock\BedrockDataFiles;
use quark\item\enchantment\EnchantmentInstance;
use quark\item\enchantment\VanillaEnchantments;
use quark\item\VanillaItems;
use quark\nbt\ReferenceNetworkNbtSerializer;
use quark\utils\Filesystem;
use quark\world\format\Chunk;
use quark\world\format\io\leveldb\ChunkDataKey;
use quark\world\format\io\leveldb\LevelDB;
use quark\world\WorldCreationOptions;
use Symfony\Component\Filesystem\Path;
use function array_map;
use function bin2hex;
use function count;
use function dirname;
use function hrtime;
use function intdiv;
use function json_encode;
use function max;
use function memory_get_peak_usage;
use function memory_get_usage;
use function memory_reset_peak_usage;
use function random_bytes;
use function round;
use function sort;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Runs $fn in seven timed rounds after a warm-up and returns the median time and the transient memory of one call.
 *
 * @phpstan-param \Closure() : mixed $fn
 * @return float[]
 * @phpstan-return array{us: float, alloc_kib: float}
 */
function measure(int $iterations, \Closure $fn) : array{
	for($i = max(1, intdiv($iterations, 10)); $i > 0; --$i){
		$fn();
	}
	$rounds = [];
	for($round = 0; $round < 7; ++$round){
		$start = hrtime(true);
		for($i = 0; $i < $iterations; ++$i){
			$fn();
		}
		$rounds[] = (hrtime(true) - $start) / $iterations / 1000;
	}
	sort($rounds);

	$base = memory_get_usage();
	memory_reset_peak_usage();
	$result = $fn();
	$alloc = memory_get_peak_usage() - $base;
	unset($result);
	return ["us" => round($rounds[3], 2), "alloc_kib" => round($alloc / 1024, 1)];
}

/**
 * @return mixed[]
 */
function compare(string $label, TreeRoot $root, int $format, BaseNbtSerializer $reference, int $iterations) : array{
	$bytes = $reference->write($root);
	if(NativeNbt::write($root, $format) !== $bytes){
		throw new \RuntimeException("$label: native output differs from pocketmine/nbt");
	}
	$result = ["bytes" => strlen($bytes)];
	foreach([
		"write" => [fn() => $reference->write($root), fn() => NativeNbt::write($root, $format)],
		"read" => [fn() => $reference->read($bytes), fn() => NativeNbt::read($bytes, $format)],
	] as $operation => [$php, $native]){
		$before = measure($iterations, $php);
		$after = measure($iterations, $native);
		$result[$operation] = ["pocketmine" => $before, "native" => $after, "speedup" => round($before["us"] / $after["us"], 1)];
	}
	$result["validate_only"] = measure($iterations, fn() => NativeNbt::length($bytes, $format));
	return $result;
}

function item() : CompoundTag{
	return VanillaItems::DIAMOND_SWORD()
		->setCustomName("§bBlade of the Benchmark")
		->setLore(["Forged for measuring", "Second line of lore"])
		->addEnchantment(new EnchantmentInstance(VanillaEnchantments::SHARPNESS(), 5))
		->addEnchantment(new EnchantmentInstance(VanillaEnchantments::UNBREAKING(), 3))
		->nbtSerialize();
}

/**
 * @return CompoundTag[]
 * @phpstan-return list<CompoundTag>
 */
function slots(int $count) : array{
	$items = [];
	for($slot = 0; $slot < $count; ++$slot){
		$items[] = item()->setByte("Slot", $slot);
	}
	return $items;
}

function entity() : CompoundTag{
	$attribute = fn(string $name) => CompoundTag::create()->setString("Name", $name)->setFloat("Base", 20.0)->setFloat("Current", 20.0)->setFloat("Max", 20.0)->setFloat("Min", 0.0);
	return CompoundTag::create()
		->setString("identifier", "minecraft:player")
		->setTag("Pos", new ListTag([new DoubleTag(128.5), new DoubleTag(64.0), new DoubleTag(-32.5)]))
		->setTag("Motion", new ListTag([new DoubleTag(0.0), new DoubleTag(-0.08), new DoubleTag(0.0)]))
		->setTag("Rotation", new ListTag([new FloatTag(90.0), new FloatTag(0.0)]))
		->setTag("Attributes", new ListTag(array_map($attribute, ["minecraft:health", "minecraft:absorption", "minecraft:movement", "minecraft:attack_damage", "minecraft:player.hunger", "minecraft:player.saturation", "minecraft:player.exhaustion", "minecraft:player.level", "minecraft:player.experience", "minecraft:knockback_resistance"])))
		->setTag("Inventory", new ListTag(slots(36)))
		->setTag("EnderChestInventory", new ListTag(slots(27)))
		->setInt("playerGameType", 0)
		->setLong("firstPlayed", 1700000000000)
		->setString("NameTag", "Benchmark");
}

function chest() : CompoundTag{
	return CompoundTag::create()
		->setString("id", "Chest")
		->setInt("x", 10)->setInt("y", 64)->setInt("z", -20)
		->setString("CustomName", "Loot")
		->setTag("Items", new ListTag(slots(27)));
}

/**
 * @return mixed[]
 */
function codecs() : array{
	$network = new ReferenceNetworkNbtSerializer();
	$bedrock = new LittleEndianNbtSerializer();
	$java = new BigEndianNbtSerializer();

	$world = Path::join(sys_get_temp_dir(), "quark-nbt-benchmark-" . bin2hex(random_bytes(6)));
	LevelDB::generate($world, "benchmark", WorldCreationOptions::create());
	$level = NativeNbt::read(Filesystem::fileGetContents(Path::join($world, "level.dat")), NativeNbt::BEDROCK, 8)->mustGetCompoundTag();
	Filesystem::recursiveUnlink($world);

	$palette = Filesystem::fileGetContents(BedrockDataFiles::CANONICAL_BLOCK_STATES_NBT);
	$paletteBefore = measure(3, fn() => $network->readMultiple($palette));
	$paletteAfter = measure(3, fn() => NativeNbt::readMultiple($palette, NativeNbt::NETWORK));

	return [
		"item_bedrock" => compare("item", new TreeRoot(item()), NativeNbt::BEDROCK, $bedrock, 2000),
		"item_network" => compare("item", new TreeRoot(item()), NativeNbt::NETWORK, $network, 2000),
		"entity_bedrock" => compare("entity", new TreeRoot(entity()), NativeNbt::BEDROCK, $bedrock, 100),
		"player_java" => compare("player", new TreeRoot(entity()), NativeNbt::JAVA, $java, 100),
		"tile_chest_bedrock" => compare("tile", new TreeRoot(chest()), NativeNbt::BEDROCK, $bedrock, 200),
		"tile_chest_network" => compare("tile", new TreeRoot(chest()), NativeNbt::NETWORK, $network, 200),
		"level_dat_bedrock" => compare("level.dat", new TreeRoot($level), NativeNbt::BEDROCK, $bedrock, 1000),
		"block_palette_network" => [
			"documents" => count(NativeNbt::readMultiple($palette, NativeNbt::NETWORK)),
			"read_ms" => ["pocketmine" => round($paletteBefore["us"] / 1000, 1), "native" => round($paletteAfter["us"] / 1000, 1), "speedup" => round($paletteBefore["us"] / $paletteAfter["us"], 1)],
			"alloc_kib" => ["pocketmine" => $paletteBefore["alloc_kib"], "native" => $paletteAfter["alloc_kib"]],
		],
	];
}

/**
 * Loads and saves real chunks through the LevelDB provider, using only APIs older Quark trees have too.
 *
 * @return mixed[]
 */
function chunks(string $world, int $limit) : array{
	$provider = new LevelDB($world, new \PrefixedLogger(\GlobalLogger::get(), "benchmark"));
	$coordinates = [];
	foreach($provider->getDatabase()->getIterator() as $key => $_){
		if(strlen($key) === 9 && $key[8] === ChunkDataKey::NEW_VERSION){
			$coordinates[] = [LE::unpackSignedInt(substr($key, 0, 4)), LE::unpackSignedInt(substr($key, 4, 4))];
			if(count($coordinates) >= $limit){
				break;
			}
		}
	}

	$result = ["chunks" => count($coordinates)];
	$loaded = [];
	foreach(["cold_load", "warm_load"] as $pass){
		$loaded = [];
		$start = hrtime(true);
		foreach($coordinates as [$x, $z]){
			$loaded[] = [$x, $z, $provider->loadChunk($x, $z)?->getData()];
		}
		$result[$pass . "_ms"] = round((hrtime(true) - $start) / 1e6, 1);
	}
	$start = hrtime(true);
	foreach($loaded as [$x, $z, $data]){
		if($data !== null){
			$provider->saveChunk($x, $z, $data, Chunk::DIRTY_FLAGS_ALL);
		}
	}
	$result["save_ms"] = round((hrtime(true) - $start) / 1e6, 1);
	$result["peak_memory_mib"] = round(memory_get_peak_usage() / 1048576, 1);
	$provider->close();
	return $result;
}

$mode = $argv[1] ?? "codecs";
echo json_encode(match($mode){
	"codecs" => codecs(),
	"chunks" => chunks($argv[2] ?? throw new \InvalidArgumentException("Usage: chunks <world> [limit]"), (int) ($argv[3] ?? 1024)),
	default => throw new \InvalidArgumentException("Unknown mode $mode")
}, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
