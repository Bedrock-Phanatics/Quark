<?php

/*
 * Measures hopper tick cost on an in-memory world. "off" runs the same layout with hoppers disabled, which is what the
 * world cost before hoppers moved items.
 * Run with: php tests/benchmarks/hoppers.php [--scenario=name]
 */

declare(strict_types=1);

use quark\block\utils\HopperConfig;
use quark\block\VanillaBlocks;
use quark\entity\object\ItemEntity;
use quark\inventory\Inventory;
use quark\item\VanillaItems;
use quark\math\Facing;
use quark\math\Vector3;
use quark\world\redstone\RedstoneHarness;
use quark\world\World;

require dirname(__DIR__, 2) . "/vendor/autoload.php";

/**
 * @phpstan-param Closure(int) : void $perTick
 * @return array{avg_us: float, p95_us: float, p99_us: float, max_us: float}
 */
function measureTicks(RedstoneHarness $h, int $ticks, ?Closure $perTick = null) : array{
	$samples = [];
	for($i = 0; $i < $ticks; ++$i){
		$start = hrtime(true);
		if($perTick !== null){
			$perTick($i);
		}
		$h->tick();
		$samples[] = (hrtime(true) - $start) / 1000;
	}
	sort($samples);
	$n = count($samples);
	return [
		"avg_us" => round(array_sum($samples) / $n, 1),
		"p95_us" => round($samples[(int) ($n * 0.95)], 1),
		"p99_us" => round($samples[(int) ($n * 0.99)], 1),
		"max_us" => round($samples[$n - 1], 1),
	];
}

/**
 * @return list<array{int, int}>
 */
function grid(int $count, int $xSpacing = 1, int $zSpacing = 1) : array{
	$side = (int) ceil(sqrt($count));
	$cells = [];
	for($i = 0; $i < $count; ++$i){
		$cells[] = [intdiv($i, $side) * $xSpacing - 64, ($i % $side) * $zSpacing - 64];
	}
	return $cells;
}

function harness() : RedstoneHarness{
	return new RedstoneHarness(8);
}

function inventoryAt(World $world, int $x, int $y, int $z) : Inventory{
	$tile = $world->getTileAt($x, $y, $z);
	return $tile instanceof quark\block\tile\ContainerTile ? $tile->getInventory() : throw new RuntimeException("No container at $x $y $z");
}

/**
 * @phpstan-param Closure(RedstoneHarness) : (Closure(int) : void)|null $build
 * @return array<string, mixed>
 */
function scenario(bool $enabled, int $settle, int $ticks, Closure $build) : array{
	HopperConfig::configure($enabled, 8, 1, 512);
	gc_collect_cycles();
	$memoryBefore = memory_get_usage();
	$h = harness();
	$perTick = $build($h);
	$h->tick($settle);
	$result = measureTicks($h, $ticks, $perTick);
	$result["memory_kib"] = round((memory_get_usage() - $memoryBefore) / 1024);
	return $result;
}

function moved(World $world, array $cells, int $y) : int{
	$total = 0;
	foreach($cells as [$x, $z]){
		$total += 320 - array_sum(array_map(fn($i) => $i->getCount(), inventoryAt($world, $x, $y, $z)->getContents()));
	}
	return $total;
}

$scenarios = [];

foreach([100, 1000, 10000] as $n){
	$scenarios["idle_$n"] = static function(bool $enabled) use ($n) : array{
		//empty hoppers with nothing around them: no work ever arrives, so only the backed-off rechecks run
		return scenario($enabled, 3000, 2400, static function(RedstoneHarness $h) use ($n) : ?Closure{
			foreach(grid($n) as [$x, $z]){
				$h->set($x, 64, $z, VanillaBlocks::HOPPER());
			}
			return null;
		});
	};
	$scenarios["idle_blocked_$n"] = static function(bool $enabled) use ($n) : array{
		//loaded hoppers under empty chests and facing full ones: they sleep on inventory listeners
		return scenario($enabled, 3000, 2400, static function(RedstoneHarness $h) use ($n) : ?Closure{
			foreach(grid($n, 2) as [$x, $z]){
				$h->set($x, 65, $z, VanillaBlocks::CHEST());
				$h->set($x, 64, $z, VanillaBlocks::HOPPER()->setFacing(Facing::EAST));
				$h->set($x + 1, 64, $z, VanillaBlocks::HOPPER());
				inventoryAt($h->world, $x, 64, $z)->setItem(0, VanillaItems::DIAMOND());
				$full = inventoryAt($h->world, $x + 1, 64, $z);
				for($slot = 0; $slot < 5; ++$slot){
					$full->setItem($slot, VanillaItems::STICK()->setCount(64));
				}
			}
			return null;
		});
	};
}

foreach([100, 1000] as $n){
	$scenarios["active_$n"] = static function(bool $enabled) use ($n) : array{
		$world = null;
		$cells = grid($n, 2);
		$result = scenario($enabled, 20, 800, static function(RedstoneHarness $h) use ($cells, &$world) : ?Closure{
			$world = $h->world;
			foreach($cells as [$x, $z]){
				$h->set($x, 64, $z, VanillaBlocks::HOPPER()->setFacing(Facing::EAST));
				$h->set($x + 1, 64, $z, VanillaBlocks::CHEST());
				inventoryAt($h->world, $x, 64, $z)->setContents(array_fill(0, 5, VanillaItems::DIAMOND()->setCount(64)));
			}
			return null;
		});
		$result["items_moved"] = moved($world, $cells, 64);
		$result["expected_items"] = (int) (count($cells) * (820 / 8));
		return $result;
	};
}

$scenarios["chain_200"] = static function(bool $enabled) : array{
	$world = null;
	$result = scenario($enabled, 0, 2000, static function(RedstoneHarness $h) use (&$world) : ?Closure{
		$world = $h->world;
		for($x = -80; $x < 120; ++$x){
			$h->set($x, 64, 0, VanillaBlocks::HOPPER()->setFacing(Facing::EAST));
		}
		$h->set(120, 64, 0, VanillaBlocks::CHEST());
		inventoryAt($h->world, -80, 64, 0)->setContents(array_fill(0, 5, VanillaItems::DIAMOND()->setCount(64)));
		return null;
	});
	$result["items_delivered"] = array_sum(array_map(fn($i) => $i->getCount(), inventoryAt($world, 120, 64, 0)->getContents()));
	//the first item needs 200 hops of 8 ticks, after that one arrives every 8 ticks
	$result["expected_delivered"] = max(0, intdiv(2000 - 200 * 8, 8) + 1);
	return $result;
};

$scenarios["shared_targets_1000"] = static function(bool $enabled) : array{
	//200 chests, each fed from above and all four sides, and drained by a hopper below into a sink chest
	return scenario($enabled, 20, 800, static function(RedstoneHarness $h) : ?Closure{
		foreach(grid(200, 4, 4) as [$x, $z]){
			$h->set($x, 64, $z, VanillaBlocks::CHEST());
			$h->set($x, 65, $z, VanillaBlocks::HOPPER());
			foreach([[1, 0, Facing::WEST], [-1, 0, Facing::EAST], [0, 1, Facing::NORTH], [0, -1, Facing::SOUTH]] as [$dx, $dz, $facing]){
				$h->set($x + $dx, 64, $z + $dz, VanillaBlocks::HOPPER()->setFacing($facing));
			}
			$h->set($x, 63, $z, VanillaBlocks::HOPPER()->setFacing(Facing::DOWN));
			$h->set($x, 62, $z, VanillaBlocks::CHEST());
			foreach([[0, 65, 0], [1, 64, 0], [-1, 64, 0], [0, 64, 1], [0, 64, -1]] as [$dx, $y, $dz]){
				inventoryAt($h->world, $x + $dx, $y, $z + $dz)->setContents(array_fill(0, 5, VanillaItems::DIAMOND()->setCount(64)));
			}
		}
		return null;
	});
};

$scenarios["dropped_items_100x20"] = static function(bool $enabled) : array{
	//full hoppers with 20 resting items each, ticked like a real world ticks them, so every item reports every tick
	$entities = [];
	$result = scenario($enabled, 20, 400, static function(RedstoneHarness $h) use (&$entities) : Closure{
		foreach(grid(100, 2) as [$x, $z]){
			$h->set($x, 64, $z, VanillaBlocks::HOPPER());
			$h->set($x, 63, $z, VanillaBlocks::STONE());
			inventoryAt($h->world, $x, 64, $z)->setContents(array_fill(0, 5, VanillaItems::STICK()->setCount(64)));
			for($i = 0; $i < 20; ++$i){
				$entities[] = $h->world->dropItem(new Vector3($x + 0.5, 64.7, $z + 0.5), VanillaItems::DIAMOND(), Vector3::zero(), 0);
			}
		}
		$world = $h->world;
		return static function(int $i) use (&$entities, $world) : void{
			$tick = $world->getServer()->getTick() + 1;
			foreach($entities as $entity){
				if($entity instanceof ItemEntity && !$entity->isClosed()){
					$entity->onUpdate($tick);
				}
			}
		};
	});
	return $result;
};

$scenarios["dropped_items_collect_100x20"] = static function(bool $enabled) : array{
	//the same items falling into empty hoppers that drain into chests below
	$entities = [];
	$world = null;
	$result = scenario($enabled, 0, 400, static function(RedstoneHarness $h) use (&$entities, &$world) : Closure{
		$world = $h->world;
		foreach(grid(100, 2) as [$x, $z]){
			$h->set($x, 64, $z, VanillaBlocks::HOPPER());
			$h->set($x, 63, $z, VanillaBlocks::CHEST());
			for($i = 0; $i < 20; ++$i){
				$entities[] = $h->world->dropItem(new Vector3($x + 0.5, 66 + $i * 0.3, $z + 0.5), VanillaItems::DIAMOND(), Vector3::zero(), 0);
			}
		}
		return static function(int $i) use (&$entities, $world) : void{
			$tick = $world->getServer()->getTick() + 1;
			foreach($entities as $entity){
				if($entity instanceof ItemEntity && !$entity->isClosed() && !$entity->isFlaggedForDespawn()){
					$entity->onUpdate($tick);
				}
			}
		};
	});
	$collected = 0;
	foreach(grid(100, 2) as [$x, $z]){
		$collected += array_sum(array_map(fn($i) => $i->getCount(), [...inventoryAt($world, $x, 64, $z)->getContents(), ...inventoryAt($world, $x, 63, $z)->getContents()]));
	}
	$result["collected"] = $collected;
	return $result;
};

$scenarios["chunk_cycles_256"] = static function(bool $enabled) : array{
	HopperConfig::configure($enabled, 8, 1, 512);
	$h = harness();
	$cycle = static function() use ($h) : void{
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				$h->set($x, 64, $z, VanillaBlocks::HOPPER()->setFacing(Facing::EAST));
				inventoryAt($h->world, $x, 64, $z)->setItem(0, VanillaItems::DIAMOND());
			}
		}
		$h->tick(10);
		$h->unloadChunk(0, 0);
		$h->tick(2);
		$h->loadChunk(0, 0);
	};
	for($i = 0; $i < 5; ++$i){
		$cycle();
	}
	gc_collect_cycles();
	$memory = memory_get_usage();
	$start = hrtime(true);
	for($i = 0; $i < 50; ++$i){
		$cycle();
	}
	$elapsed = (hrtime(true) - $start) / 1e6;
	gc_collect_cycles();
	return ["ms_per_cycle" => round($elapsed / 50, 2), "memory_growth_kib_over_50_cycles" => round((memory_get_usage() - $memory) / 1024)];
};

$scenarios["redstone_toggle_1000"] = static function(bool $enabled) : array{
	$cells = grid(1000, 2, 2);
	return scenario($enabled, 20, 400, static function(RedstoneHarness $h) use ($cells) : Closure{
		foreach($cells as [$x, $z]){
			$h->set($x, 64, $z, VanillaBlocks::HOPPER()->setFacing(Facing::EAST));
			$h->set($x + 1, 64, $z, VanillaBlocks::CHEST());
			inventoryAt($h->world, $x, 64, $z)->setContents(array_fill(0, 5, VanillaItems::DIAMOND()->setCount(64)));
		}
		return static function(int $i) use ($h, $cells) : void{
			if($i % 4 !== 0){
				return;
			}
			$on = ($i / 4) % 2 === 0;
			foreach(array_slice($cells, 0, 250) as [$x, $z]){
				if($on){
					$h->place($x, 64, $z - 1, VanillaBlocks::REDSTONE());
				}else{
					$h->breakBlock($x, 64, $z - 1);
				}
			}
		};
	});
};

$only = getopt("", ["scenario:"])["scenario"] ?? null;
foreach($scenarios as $name => $run){
	if($only !== null && $only !== $name){
		continue;
	}
	$off = $run(false);
	$on = $run(true);
	echo json_encode(["scenario" => $name, "off" => $off, "on" => $on], JSON_THROW_ON_ERROR), PHP_EOL;
}
