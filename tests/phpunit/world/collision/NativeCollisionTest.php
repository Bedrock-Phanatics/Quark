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

namespace quark\world\collision;

use PHPUnit\Framework\TestCase;
use quark\block\Block;
use quark\block\RuntimeBlockStateRegistry;
use quark\block\VanillaBlocks;
use quark\entity\projectile\Projectile;
use quark\math\AxisAlignedBB;
use quark\math\collision\CollisionRegion;
use quark\math\collision\CollisionSolver;
use quark\math\collision\Raycast;
use quark\math\physics\ExplosionMath;
use quark\math\RayTraceResult;
use quark\math\Vector3;
use quark\math\VoxelRayTrace;
use quark\world\ChunkLoader;
use quark\world\Explosion;
use quark\world\format\Chunk;
use quark\world\Position;
use quark\world\redstone\RedstoneHarness;
use quark\world\World;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function mt_srand;
use const NAN;

/**
 * Checks the native collision, raycast and explosion paths against the PHP code they replaced, on a world filled
 * with every kind of block shape.
 */
final class NativeCollisionTest extends TestCase{
	private const MIN = -24;
	private const MAX = 23;
	private const FLOOR = 60;
	private const TOP = 72;

	private RedstoneHarness $h;
	private World $world;
	private LegacyCollision $legacy;
	/** @var list<Block> */
	private static array $shapes = [];
	/** @var list<Block> */
	private static array $cubes = [];

	protected function setUp() : void{
		$this->h = new RedstoneHarness(2);
		$this->world = $this->h->world;
		$this->legacy = new LegacyCollision($this->world);
		if(count(self::$shapes) === 0){
			$registry = RuntimeBlockStateRegistry::getInstance();
			foreach($registry->getAllKnownStates() as $stateId => $block){
				if($block->getIdInfo()->getTileClass() !== null){
					continue;
				}
				$info = $registry->collisionInfo[$stateId];
				if($info === RuntimeBlockStateRegistry::COLLISION_CUBE){
					self::$cubes[] = $block;
				}elseif($info !== RuntimeBlockStateRegistry::COLLISION_NONE){
					self::$shapes[] = $block;
				}
			}
		}
	}

	private static function rand(float $min, float $max) : float{
		return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
	}

	private function randomBlock() : Block{
		$r = mt_rand(0, 99);
		return match(true){
			$r < 45 => VanillaBlocks::AIR(),
			$r < 60 => self::$cubes[mt_rand(0, count(self::$cubes) - 1)],
			default => self::$shapes[mt_rand(0, count(self::$shapes) - 1)]
		};
	}

	private function fill(int $seed) : void{
		mt_srand($seed);
		for($x = self::MIN; $x <= self::MAX; ++$x){
			for($z = self::MIN; $z <= self::MAX; ++$z){
				$this->h->set($x, self::FLOOR, $z, VanillaBlocks::STONE());
				for($y = self::FLOOR + 1; $y <= self::TOP; ++$y){
					if(mt_rand(0, 2) === 0){
						$this->h->set($x, $y, $z, $this->randomBlock());
					}
				}
			}
		}
	}

	private function randomBox(float $maxSize = 1.8) : AxisAlignedBB{
		$x = self::rand(self::MIN + 3, self::MAX - 3);
		$y = self::rand(self::FLOOR + 1, self::TOP - 3);
		$z = self::rand(self::MIN + 3, self::MAX - 3);
		$w = self::rand(0.1, $maxSize / 2);
		return new AxisAlignedBB($x - $w, $y, $z - $w, $x + $w, $y + self::rand(0.1, $maxSize), $z + $w);
	}

	/**
	 * @param AxisAlignedBB[] $boxes
	 * @return list<list<float>>
	 */
	private static function boxes(array $boxes) : array{
		return array_values(array_map(fn(AxisAlignedBB $b) => [$b->minX, $b->minY, $b->minZ, $b->maxX, $b->maxY, $b->maxZ], $boxes));
	}

	/** @return list<int|float> */
	private static function hit(?RayTraceResult $r) : array{
		return $r === null ? [] : [$r->hitFace, $r->hitVector->x, $r->hitVector->y, $r->hitVector->z, ...self::boxes([$r->bb])[0]];
	}

	private function assertCollisionMatches(int $cases) : void{
		for($i = 0; $i < $cases; ++$i){
			$bb = $this->randomBox();
			self::assertSame(self::boxes($this->legacy->blockCollisionBoxes($bb)), self::boxes($this->world->getBlockCollisionBoxes($bb)), "boxes for $bb");

			$dx = self::rand(-1.5, 1.5);
			$dy = mt_rand(0, 3) === 0 ? self::rand(-3.5, 0.6) : self::rand(-0.6, 0.6);
			$dz = self::rand(-1.5, 1.5);
			$step = mt_rand(0, 1) === 0 ? 0.6 : 0.0;
			$onGround = mt_rand(0, 1) === 0;
			[$ex, $ey, $ez, $ebb, $stepped] = $this->legacy->move($bb, $dx, $dy, $dz, $step, $onGround);
			$result = CollisionSolver::move($this->world->getCollisionRegion(CollisionSolver::queryBox($bb, $dx, $dy, $dz, $step)), $bb, $dx, $dy, $dz, $step, $onGround);
			self::assertSame([$ex, $ey, $ez, $stepped, self::boxes([$ebb])], [$result->dx, $result->dy, $result->dz, $result->stepped, self::boxes([$result->boundingBox])], "move $bb by $dx $dy $dz");
		}
	}

	public function testCollisionMatchesLegacyOnEveryShape() : void{
		$this->fill(1);
		$this->assertCollisionMatches(3000);
	}

	public function testBlockChangesNeverLeaveStaleCollision() : void{
		$this->fill(2);
		$this->assertCollisionMatches(300);
		for($round = 0; $round < 20; ++$round){
			for($n = 0; $n < 200; ++$n){
				//chunk borders and section borders get extra attention
				$x = mt_rand(0, 3) === 0 ? mt_rand(-1, 0) * 16 + mt_rand(-1, 0) : mt_rand(self::MIN, self::MAX);
				$z = mt_rand(0, 3) === 0 ? mt_rand(-1, 0) * 16 + mt_rand(-1, 0) : mt_rand(self::MIN, self::MAX);
				$y = mt_rand(0, 3) === 0 ? 63 + mt_rand(0, 1) : mt_rand(self::FLOOR + 1, self::TOP);
				$this->h->set($x, $y, $z, $this->randomBlock());
			}
			$this->assertCollisionMatches(150);
		}
	}

	public function testOnlyTheChangedCellsAreDropped() : void{
		$this->fill(3);
		$bb = new AxisAlignedBB(-8, 61, -8, 8, 70, 8);
		$this->world->getBlockCollisionBoxes($bb);
		$region = (fn() : CollisionRegion => $this->collisionRegion)->call($this->world);
		self::assertSame([], $region->unknownCells(0, 4, 0));

		$this->h->set(5, 65, 5, VanillaBlocks::OAK_FENCE());
		self::assertCount(7, $region->unknownCells(0, 4, 0) ?? []);
		self::assertFalse($region->isKnown(5, 66, 5));
		self::assertTrue($region->isKnown(7, 65, 5));
		self::assertSame(self::boxes($this->legacy->blockCollisionBoxes($bb)), self::boxes($this->world->getBlockCollisionBoxes($bb)));
		self::assertSame([], $region->unknownCells(0, 4, 0));
	}

	public function testBulkEditsReloadTheWholeSection() : void{
		$this->fill(9);
		$bb = new AxisAlignedBB(-16, 61, -16, 16, 72, 16);
		$this->world->getBlockCollisionBoxes($bb);
		for($x = 0; $x < 16; ++$x){
			for($z = 0; $z < 16; ++$z){
				for($y = 64; $y < 72; ++$y){
					$this->h->set($x, $y, $z, $this->randomBlock());
				}
			}
		}
		self::assertSame(self::boxes($this->legacy->blockCollisionBoxes($bb)), self::boxes($this->world->getBlockCollisionBoxes($bb)));
		$this->assertCollisionMatches(300);
	}

	public function testChunkReplacementAndUnloadDropCollision() : void{
		$this->fill(4);
		$bb = new AxisAlignedBB(-4, 61, -4, 4, 70, 4);
		$this->world->getBlockCollisionBoxes($bb);

		//a loader keeps the replaced chunk from being queued for unload, which needs a provider
		$this->world->registerChunkLoader(new ChunkLoader(), 0, 0, false);
		$this->world->setChunk(0, 0, new Chunk([], true));
		self::assertSame(self::boxes($this->legacy->blockCollisionBoxes($bb)), self::boxes($this->world->getBlockCollisionBoxes($bb)));

		$this->h->set(1, 61, 1, VanillaBlocks::STONE());
		self::assertNotSame([], $this->world->getBlockCollisionBoxes(new AxisAlignedBB(1.2, 61.2, 1.2, 1.8, 61.8, 1.8)));
		$this->h->unloadChunk(0, 0);
		//unloaded chunks collide like air, as they always have
		self::assertSame([], $this->world->getBlockCollisionBoxes(new AxisAlignedBB(1.2, 61.2, 1.2, 1.8, 61.8, 1.8)));
		self::assertSame(self::boxes($this->legacy->blockCollisionBoxes($bb)), self::boxes($this->world->getBlockCollisionBoxes($bb)));
	}

	public function testClearCacheEmptiesTheRegion() : void{
		$this->fill(5);
		$this->world->getBlockCollisionBoxes(new AxisAlignedBB(-8, 61, -8, 8, 70, 8));
		$region = (fn() : CollisionRegion => $this->collisionRegion)->call($this->world);
		self::assertGreaterThan(0, $region->getSectionCount());
		$this->world->clearCache(true);
		self::assertSame(0, $region->getSectionCount());
	}

	public function testOutOfRangeQueriesFailInsteadOfColliding() : void{
		$this->expectException(\ValueError::class);
		$this->world->getBlockCollisionBoxes(new AxisAlignedBB(1e9, 64, 0, 1e9 + 1, 65, 1));
	}

	public function testProjectileRaysMatchLegacy() : void{
		$this->fill(6);
		for($i = 0; $i < 3000; ++$i){
			$start = new Vector3(self::rand(self::MIN + 6, self::MAX - 6), self::rand(self::FLOOR + 1, self::TOP), self::rand(self::MIN + 6, self::MAX - 6));
			$end = $start->add(self::rand(-5, 5), self::rand(-5, 5), self::rand(-5, 5));
			$expected = $this->legacy->projectileBlocks($start, $end);
			$region = $this->world->getCollisionRegion(new AxisAlignedBB(
				min($start->x, $end->x), min($start->y, $end->y), min($start->z, $end->z),
				max($start->x, $end->x), max($start->y, $end->y), max($start->z, $end->z)
			));
			$hit = Raycast::blocks($region, $start, $end);
			self::assertSame(
				$expected === null ? [] : [$expected[0]->getPosition()->x, $expected[0]->getPosition()->y, $expected[0]->getPosition()->z, ...self::hit($expected[1])],
				$hit === null ? [] : [$hit->blockX, $hit->blockY, $hit->blockZ, ...self::hit($hit)],
				"ray $start to $end"
			);

			$boxes = [];
			for($n = mt_rand(0, 6); $n > 0; --$n){
				$c = $start->add(self::rand(-3, 3), self::rand(-3, 3), self::rand(-3, 3));
				$boxes[] = new AxisAlignedBB($c->x - 0.3, $c->y, $c->z - 0.3, $c->x + 0.3, $c->y + 1.8, $c->z + 0.3);
			}
			$expected = $this->legacy->projectileEntities($start, $end, $boxes);
			$actual = Raycast::boxes($start, $end, $boxes, 0.3);
			self::assertSame($expected === null ? [] : [$expected[0], ...self::hit($expected[1])], $actual === null ? [] : [$actual[0], ...self::hit($actual[1])]);
		}
	}

	public function testExposureMatchesLegacy() : void{
		$this->fill(7);
		for($i = 0; $i < 300; ++$i){
			$origin = new Vector3(self::rand(-8, 8), self::rand(61, 70), self::rand(-8, 8));
			$bb = $this->randomBox(2.5);
			$region = $this->world->getCollisionRegion($bb->union(new AxisAlignedBB($origin->x, $origin->y, $origin->z, $origin->x, $origin->y, $origin->z)));
			self::assertSame($this->legacy->exposure($origin, $bb), ExplosionMath::exposure($region, $origin, $bb), "exposure of $bb from $origin");
		}
	}

	public function testExplosionsMatchLegacyIncludingRng() : void{
		$this->fill(8);
		$ignitions = new \ReflectionProperty(Explosion::class, "fireIgnitions");
		foreach([[0.5, 4.0, 0.0], [3.25, 3.0, 1 / 3], [-7.5, 6.0, 0.0], [15.9, 4.0, 1.0], [0, 12.0, 0.0], [-0.1, 0.5, 0.5], [2.5, 30.0, 0.0]] as $n => [$at, $radius, $fire]){
			$source = new Vector3($at, 64.5, -$at);
			mt_srand(100 + $n);
			[$affected, $ignited] = $this->legacy->explodeA($source, $radius, $fire);
			$expectedNext = mt_rand();

			mt_srand(100 + $n);
			$explosion = new Explosion(Position::fromObject($source, $this->world), $radius, null, $fire);
			$explosion->explodeA();
			self::assertSame($expectedNext, mt_rand(), "rng after explosion $n");
			self::assertSame($affected, array_keys($explosion->affectedBlocks), "affected blocks of explosion $n");
			/** @var array<int, Block> $fires */
			$fires = $ignitions->getValue($explosion);
			self::assertSame($ignited, array_keys($fires), "ignitions of explosion $n");
		}
	}

	public function testExplosionsPastTheNativeStepLimitUseTheRayMarch() : void{
		$this->fill(8);
		$grid = new \ReflectionMethod(Explosion::class, "createResistanceGrid");
		$explosion = new Explosion(Position::fromObject(new Vector3(0.5, 64.5, 0.5), $this->world), 4.0);
		self::assertNotNull($grid->invoke($explosion));
		$explosion->stepLen = 0.0005;
		self::assertNull($grid->invoke($explosion));

		$explosion->stepLen = 0.0;
		$this->expectException(\InvalidArgumentException::class);
		$explosion->explodeA();
	}

	public function testProjectileFallbackTraceStaysUnderTheVoxelLimit() : void{
		$max = (new \ReflectionClassConstant(Projectile::class, "MAX_FALLBACK_TRACE"))->getValue();
		self::assertIsInt($max);
		//starting just under a corner and going diagonally adds a cell on every axis
		$start = new Vector3(0.9999, 0.9999, 0.9999);
		$d = ($max - 0.001) / 3;
		$voxels = 0;
		foreach(VoxelRayTrace::betweenPoints($start, $start->add($d, $d, $d)) as $_){
			++$voxels;
		}
		self::assertGreaterThan($max, $voxels);
	}

	public function testExplosionRadiusMustBeFinite() : void{
		$this->expectException(\InvalidArgumentException::class);
		new Explosion(Position::fromObject(new Vector3(0.5, 64.5, 0.5), $this->world), NAN);
	}
}
