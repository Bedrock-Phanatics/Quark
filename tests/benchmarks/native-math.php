<?php

/*
 * Compares the ext-qmath paths with the PHP code they replaced, on the same in-memory world.
 * Run with: php tests/benchmarks/native-math.php [--runs=5]
 */

declare(strict_types=1);

use quark\block\RuntimeBlockStateRegistry;
use quark\block\VanillaBlocks;
use quark\math\AxisAlignedBB;
use quark\math\collision\CollisionSolver;
use quark\math\collision\Raycast;
use quark\math\physics\ExplosionMath;
use quark\math\Vector3;
use quark\utils\Random;
use quark\world\collision\LegacyCollision;
use quark\world\Explosion;
use quark\world\generator\noise\LegacySimplex;
use quark\world\generator\noise\Simplex;
use quark\world\Position;
use quark\world\redstone\RedstoneHarness;

require dirname(__DIR__, 2) . "/vendor/autoload.php";

$runs = (int) (getopt("", ["runs:"])["runs"] ?? 5);

$h = new RedstoneHarness(3);
$world = $h->world;
$registry = RuntimeBlockStateRegistry::getInstance();
$shapes = [];
foreach($registry->getAllKnownStates() as $id => $block){
	if($block->getIdInfo()->getTileClass() === null && $registry->collisionInfo[$id] !== RuntimeBlockStateRegistry::COLLISION_NONE){
		$shapes[] = $block;
	}
}
mt_srand(1);
//a built-up surface: stone floor, scattered shapes, a few walls of cubes
for($x = -40; $x < 40; ++$x){
	for($z = -40; $z < 40; ++$z){
		$h->set($x, 63, $z, VanillaBlocks::STONE());
		if(mt_rand(0, 4) === 0){
			$h->set($x, 64, $z, $shapes[mt_rand(0, count($shapes) - 1)]);
		}
		if($x % 9 === 0){
			for($y = 64; $y < 68; ++$y){
				$h->set($x, $y, $z, VanillaBlocks::STONE_BRICKS());
			}
		}
	}
}

function r(float $a, float $b) : float{ return $a + mt_rand() / mt_getrandmax() * ($b - $a); }

/**
 * @return float median microseconds per call
 */
function bench(int $runs, int $n, Closure $setup, Closure $fn) : float{
	$times = [];
	for($run = 0; $run < $runs; ++$run){
		$setup();
		$t = hrtime(true);
		for($i = 0; $i < $n; ++$i){
			$fn($i);
		}
		$times[] = (hrtime(true) - $t) / $n / 1000;
	}
	sort($times);
	return $times[intdiv(count($times), 2)];
}

$results = [];
$row = function(string $name, float $php, float $native) use (&$results) : void{
	$results[] = [$name, $php, $native];
};

//entity moves: walking boxes near the floor, the common case
mt_srand(2);
$moves = [];
for($i = 0; $i < 2000; ++$i){
	$x = r(-30, 30);
	$z = r(-30, 30);
	$moves[] = [new AxisAlignedBB($x - 0.3, 64, $z - 0.3, $x + 0.3, 65.8, $z + 0.3), r(-0.3, 0.3), -0.08, r(-0.3, 0.3)];
}
$cachedLegacy = new LegacyCollision($world, true);
$legacyMove = fn(int $i) => $cachedLegacy->move($moves[$i % 2000][0], $moves[$i % 2000][1], $moves[$i % 2000][2], $moves[$i % 2000][3], 0.6, true);
$nativeMove = function(int $i) use ($world, $moves) : void{
	[$bb, $dx, $dy, $dz] = $moves[$i % 2000];
	CollisionSolver::move($world->getCollisionRegion(CollisionSolver::queryBox($bb, $dx, $dy, $dz, 0.6)), $bb, $dx, $dy, $dz, 0.6, true);
};
for($i = 0; $i < 2000; ++$i){ $legacyMove($i); $nativeMove($i); }
$row("entity move (warm cache)", bench($runs, 20000, fn() => null, $legacyMove), bench($runs, 20000, fn() => null, $nativeMove));

//first queries in an area nobody has touched since the cache was cleared
$row("entity move (cold cache)", bench($runs, 2000, function() use (&$cachedLegacy, $world) : void{
	$cachedLegacy = new LegacyCollision($world, true);
}, $legacyMove), bench($runs, 2000, fn() => $world->clearCache(true), $nativeMove));

//a block changing next to a moving entity every time, like redstone or a farm
$changing = function(LegacyCollision|null $legacy) use ($h, $world) : Closure{
	return function(int $i) use ($legacy, $h, $world) : void{
		$x = ($i % 50) - 25;
		$h->set($x, 64, 5, $i % 2 === 0 ? VanillaBlocks::OAK_FENCE() : VanillaBlocks::AIR());
		$bb = new AxisAlignedBB($x - 0.3, 64, 4.4, $x + 0.3, 65.8, 5);
		if($legacy !== null){
			$legacy->forget($x, 64, 5);
			$legacy->move($bb, 0.2, -0.08, 0.2, 0.6, true);
		}else{
			CollisionSolver::move($world->getCollisionRegion(CollisionSolver::queryBox($bb, 0.2, -0.08, 0.2, 0.6)), $bb, 0.2, -0.08, 0.2, 0.6, true);
		}
	};
};
$changeLegacy = new LegacyCollision($world, true);
$setOnly = bench($runs, 2000, fn() => null, fn(int $i) => $h->set(($i % 50) - 25, 64, 5, $i % 2 === 0 ? VanillaBlocks::OAK_FENCE() : VanillaBlocks::AIR()));
$row("block change + move (minus setBlockAt)", bench($runs, 2000, fn() => null, $changing($changeLegacy)) - $setOnly, bench($runs, 2000, fn() => null, $changing(null)) - $setOnly);

//projectile block pass, arrows flying 3 blocks a tick
mt_srand(3);
$rays = [];
for($i = 0; $i < 2000; ++$i){
	$s = new Vector3(r(-30, 30), r(64, 67), r(-30, 30));
	$rays[] = [$s, $s->add(r(-3, 3), r(-1, 0.5), r(-3, 3))];
}
$plainLegacy = new LegacyCollision($world);
$row("projectile block ray", bench($runs, 5000, fn() => null, fn(int $i) => $plainLegacy->projectileBlocks($rays[$i % 2000][0], $rays[$i % 2000][1])), bench($runs, 5000, fn() => null, function(int $i) use ($world, $rays) : void{
	[$s, $e] = $rays[$i % 2000];
	Raycast::blocks($world->getCollisionRegion(new AxisAlignedBB(min($s->x, $e->x), min($s->y, $e->y), min($s->z, $e->z), max($s->x, $e->x), max($s->y, $e->y), max($s->z, $e->z))), $s, $e);
}));

//explosion exposure of one player-sized entity
$origin = new Vector3(0.5, 64.5, 0.5);
$target = new AxisAlignedBB(2.2, 64, 2.2, 2.8, 65.8, 2.8);
$row("explosion exposure", bench($runs, 200, fn() => null, fn() => $plainLegacy->exposure($origin, $target)), bench($runs, 200, fn() => null, function() use ($world, $origin, $target) : void{
	ExplosionMath::exposure($world->getCollisionRegion($target->union(new AxisAlignedBB(0.5, 64.5, 0.5, 0.5, 64.5, 0.5))), $origin, $target);
}));

//TNT block rays, including filling the resistance grid
$row("explosion rays (TNT, radius 4)", bench($runs, 30, fn() => mt_srand(4), fn() => $plainLegacy->explodeA($origin, 4.0, 0.0)), bench($runs, 30, fn() => mt_srand(4), function() use ($origin, $world) : void{
	(new Explosion(Position::fromObject($origin, $world), 4.0))->explodeA();
}));

//the 3D noise one Normal chunk samples
$legacyNoise = new LegacySimplex(new Random(1), 4, 1 / 4, 1 / 32);
$nativeNoise = new Simplex(new Random(1), 4, 1 / 4, 1 / 32);
$row("noise per chunk (16x128x16)", bench($runs, 20, fn() => null, fn(int $i) => $legacyNoise->getFastNoise3D(16, 128, 16, 4, 8, 4, $i * 16, 0, 0)), bench($runs, 20, fn() => null, fn(int $i) => $nativeNoise->getFastNoise3D(16, 128, 16, 4, 8, 4, $i * 16, 0, 0)));

printf("%-42s %12s %12s %8s\n", "operation (median of $runs runs)", "PHP µs", "native µs", "speedup");
foreach($results as [$name, $php, $native]){
	printf("%-42s %12.2f %12.2f %7.1fx\n", $name, $php, $native, $php / $native);
}
printf("peak memory %.1f MiB\n", memory_get_peak_usage(true) / 1048576);
