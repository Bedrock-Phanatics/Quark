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

namespace quark\entity;

use PHPUnit\Framework\TestCase;
use quark\block\VanillaBlocks;
use quark\math\AxisAlignedBB;
use quark\math\Facing;
use quark\math\physics\Physics;
use quark\math\Vector2;
use quark\math\Vector3;
use quark\world\collision\LegacyCollision;
use quark\world\redstone\RedstoneHarness;
use quark\world\World;
use function array_map;
use function atan2;
use function cos;
use function deg2rad;
use function fmod;
use function mt_getrandmax;
use function mt_rand;
use function mt_srand;
use function sin;
use function sort;
use function spl_object_id;
use function sqrt;
use const M_PI;
use const M_PI_2;

final class EntityMovementTest extends TestCase{
	private RedstoneHarness $h;
	/** @var list<Entity> */
	private array $entities = [];

	protected function setUp() : void{
		$this->h = new RedstoneHarness(1);
		for($x = -6; $x <= 6; ++$x){
			for($z = -6; $z <= 6; ++$z){
				$this->h->set($x, 63, $z, VanillaBlocks::STONE());
			}
		}
		$this->h->set(1, 64, 0, VanillaBlocks::OAK_SLAB());
		$this->h->set(2, 64, 0, VanillaBlocks::STONE());
		$this->h->set(2, 65, 0, VanillaBlocks::OAK_STAIRS());
		$this->h->set(0, 64, 2, VanillaBlocks::OAK_FENCE());
		$this->h->set(-2, 64, 0, VanillaBlocks::COBBLESTONE_WALL());
		$this->h->set(0, 67, -2, VanillaBlocks::STONE());
	}

	protected function tearDown() : void{
		//they were never added to the world, so closing them must not try to remove them
		$closed = new \ReflectionProperty(Entity::class, "closed");
		foreach($this->entities as $entity){
			$closed->setValue($entity, true);
		}
		$this->entities = [];
	}

	private static function rand(float $min, float $max) : float{
		return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
	}

	private function entity(float $x, float $y, float $z, float $stepHeight = 0.6) : MovementTestEntity{
		$entity = (new \ReflectionClass(MovementTestEntity::class))->newInstanceWithoutConstructor();
		$set = static function(string $property, mixed $value) use ($entity) : void{
			(new \ReflectionProperty(Entity::class, $property))->setValue($entity, $value);
		};
		$set("id", 1);
		$set("location", new Location($x, $y, $z, $this->h->world, 0, 0));
		$set("size", new EntitySizeInfo(1.8, 0.6));
		$set("motion", new Vector3(0, 0, 0));
		$set("drag", 0.02);
		$set("gravity", 0.08);
		$set("stepHeight", $stepHeight);
		$entity->boundingBox = new AxisAlignedBB($x - 0.3, $y, $z - 0.3, $x + 0.3, $y + 1.8, $z + 0.3);
		$this->entities[] = $entity;
		return $entity;
	}

	public function testMoveKeepsLocationBoundingBoxAndFlagsInStep() : void{
		$legacy = new LegacyCollision($this->h->world);
		$move = new \ReflectionMethod(Entity::class, "move");
		$ySize = new \ReflectionProperty(Entity::class, "ySize");
		mt_srand(11);
		for($i = 0; $i < 2000; ++$i){
			$entity = $this->entity(self::rand(-4, 4), mt_rand(0, 2) === 0 ? 64.0 : self::rand(64, 67), self::rand(-4, 4));
			$entity->onGround = mt_rand(0, 1) === 0;
			$entity->setMotion(new Vector3(1, 1, 1));
			$before = clone $entity->boundingBox;
			$dx = self::rand(-1, 1);
			$dy = self::rand(-1.2, 0.5);
			$dz = self::rand(-1, 1);

			[$ex, $ey, $ez, $ebb, $stepped] = $legacy->move($before, $dx, $dy, $dz, 0.6, $entity->onGround);
			$expectedYSize = $stepped ? $ey : 0.0;
			$move->invoke($entity, $dx, $dy, $dz);

			$bb = $entity->boundingBox;
			self::assertSame([$ebb->minX, $ebb->minY, $ebb->minZ, $ebb->maxX, $ebb->maxY, $ebb->maxZ], [$bb->minX, $bb->minY, $bb->minZ, $bb->maxX, $bb->maxY, $bb->maxZ]);
			self::assertSame($expectedYSize, $ySize->getValue($entity));
			$location = $entity->getLocation();
			self::assertSame([($bb->minX + $bb->maxX) / 2, $bb->minY - $expectedYSize, ($bb->minZ + $bb->maxZ) / 2], [$location->x, $location->y, $location->z]);
			self::assertSame($ey !== $dy && $dy < 0, $entity->onGround);
			self::assertSame($ey !== $dy, $entity->isCollidedVertically);
			self::assertSame($ex !== $dx || $ez !== $dz, $entity->isCollidedHorizontally);
			$motion = $entity->getMotion();
			self::assertSame([$ex !== $dx ? 0 : 1, $ez !== $dz ? 0 : 1], [$motion->x, $motion->z], "motion is cancelled once per blocked axis");
		}
	}

	public function testNearbyEntitiesMatchTheOldFilter() : void{
		mt_srand(15);
		$world = $this->h->world;
		$byChunk = [];
		$all = [];
		for($i = 0; $i < 60; ++$i){
			$entity = $this->entity(self::rand(-20, 20), self::rand(60, 70), self::rand(-20, 20));
			$entity->boundingBox = new AxisAlignedBB(
				$entity->boundingBox->minX, $entity->boundingBox->minY, $entity->boundingBox->minZ,
				$entity->boundingBox->minX + self::rand(0, 2), $entity->boundingBox->minY + self::rand(0, 2), $entity->boundingBox->minZ + self::rand(0, 2)
			);
			$byChunk[World::chunkHash($entity->getLocation()->getFloorX() >> 4, $entity->getLocation()->getFloorZ() >> 4)][$i + 1] = $entity;
			$all[] = $entity;
		}
		(new \ReflectionProperty(World::class, "entitiesByChunk"))->setValue($world, $byChunk);
		for($i = 0; $i < 500; ++$i){
			$x = self::rand(-20, 20);
			$y = self::rand(60, 70);
			$z = self::rand(-20, 20);
			$bb = new AxisAlignedBB($x, $y, $z, $x + self::rand(0, 6), $y + self::rand(0, 3), $z + self::rand(0, 6));
			$exclude = mt_rand(0, 1) === 0 ? $all[mt_rand(0, 59)] : null;
			$expected = [];
			foreach($byChunk as $entities){
				foreach($entities as $entity){
					$e = $entity->boundingBox;
					if($entity !== $exclude &&
						$bb->maxX - $e->minX > 0.00001 && $e->maxX - $bb->minX > 0.00001 &&
						$bb->maxY - $e->minY > 0.00001 && $e->maxY - $bb->minY > 0.00001 &&
						$bb->maxZ - $e->minZ > 0.00001 && $e->maxZ - $bb->minZ > 0.00001
					){
						$expected[] = spl_object_id($entity);
					}
				}
			}
			$got = array_map(fn(Entity $e) => spl_object_id($e), $world->getNearbyEntities($bb, $exclude));
			sort($expected);
			sort($got);
			self::assertSame($expected, $got);
		}
		(new \ReflectionProperty(World::class, "entitiesByChunk"))->setValue($world, []);
	}

	public function testMovingFarOutsideTheRegionRangeStaysPut() : void{
		$this->expectOutputRegex("/blocked: Coordinates are outside/");
		$entity = $this->entity(4e8, 64, 0);
		(new \ReflectionMethod(Entity::class, "move"))->invoke($entity, 0.5, -0.5, 0.5);
		self::assertSame(4e8, $entity->getLocation()->x);
		self::assertSame(64.0, $entity->getLocation()->y);
	}

	public function testFrictionAndGravityMatchTheOldFormula() : void{
		$entity = $this->entity(0.5, 64, 0.5);
		$tryChangeMovement = new \ReflectionMethod(Entity::class, "tryChangeMovement");
		mt_srand(12);
		for($i = 0; $i < 500; ++$i){
			$motion = new Vector3(self::rand(-2, 2), self::rand(-2, 2), self::rand(-2, 2));
			$entity->onGround = mt_rand(0, 1) === 0;
			$entity->setHasGravity(mt_rand(0, 3) !== 0);
			$entity->setMotion($motion);
			$tryChangeMovement->invoke($entity);

			$friction = 1 - 0.02;
			$mY = $motion->y;
			if($entity->hasGravity()){
				$mY -= 0.08;
			}
			$mY *= $friction;
			if($entity->onGround){
				$friction *= VanillaBlocks::STONE()->getFrictionFactor();
			}
			$got = $entity->getMotion();
			self::assertSame([$motion->x * $friction, $mY, $motion->z * $friction], [$got->x, $got->y, $got->z]);
		}
	}

	public function testDirectionsMatchTheOldFormulas() : void{
		$entity = $this->entity(0, 64, 0);
		mt_srand(13);
		for($i = 0; $i < 2000; ++$i){
			$yaw = $i < 8 ? $i * 45.0 : self::rand(-720, 720);
			$pitch = self::rand(-90, 90);
			$entity->setRotation($yaw, $pitch);

			$y = -sin(deg2rad($pitch));
			$xz = cos(deg2rad($pitch));
			$expected = (new Vector3(-$xz * sin(deg2rad($yaw)), $y, $xz * cos(deg2rad($yaw))))->normalize();
			$got = $entity->getDirectionVector();
			self::assertSame([$expected->x, $expected->y, $expected->z], [$got->x, $got->y, $got->z]);

			$plane = (new Vector2(-cos(deg2rad($yaw) - M_PI_2), -sin(deg2rad($yaw) - M_PI_2)))->normalize();
			$gotPlane = $entity->getDirectionPlane();
			self::assertSame([$plane->x, $plane->y], [$gotPlane->x, $gotPlane->y]);

			$angle = fmod($yaw, 360);
			if($angle < 0){
				$angle += 360.0;
			}
			$facing = match(true){
				(0 <= $angle && $angle < 45) || (315 <= $angle && $angle < 360) => Facing::SOUTH,
				45 <= $angle && $angle < 135 => Facing::WEST,
				135 <= $angle && $angle < 225 => Facing::NORTH,
				default => Facing::EAST
			};
			self::assertSame($facing, $entity->getHorizontalFacing(), "yaw $yaw");
		}
	}

	public function testKnockBackAndRotationMatchTheOldFormulas() : void{
		mt_srand(14);
		for($i = 0; $i < 1000; ++$i){
			$motion = new Vector3(self::rand(-1, 1), self::rand(-1, 1), self::rand(-1, 1));
			$x = self::rand(-3, 3);
			$z = self::rand(-3, 3);
			$force = self::rand(0.1, 1);
			$limit = mt_rand(0, 1) === 0 ? null : self::rand(0.1, 1);
			$f = 1 / sqrt($x * $x + $z * $z);
			$motionY = $motion->y / 2 + $force;
			$motionY = $motionY > ($limit ?? $force) ? ($limit ?? $force) : $motionY;
			$got = Physics::knockBack($motion, $x, $z, $force, $limit) ?? throw new \AssertionError("direction is not zero");
			self::assertSame([$motion->x / 2 + $x * $f * $force, $motionY, $motion->z / 2 + $z * $f * $force], [$got->x, $got->y, $got->z]);

			[$yaw, $pitch] = Physics::motionRotation($motion);
			self::assertSame([atan2($motion->x, $motion->z) * 180 / M_PI, atan2($motion->y, sqrt(($motion->x ** 2) + ($motion->z ** 2))) * 180 / M_PI], [$yaw, $pitch]);
		}
		self::assertNull(Physics::knockBack(new Vector3(0, 0, 0), 0, 0));
	}
}
