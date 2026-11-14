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
use quark\entity\effect\EffectInstance;
use quark\entity\effect\EffectManager;
use quark\entity\effect\VanillaEffects;
use quark\event\entity\EntityDamageEvent;
use quark\math\AxisAlignedBB;
use quark\math\Vector3;
use quark\world\redstone\RedstoneHarness;
use quark\world\sound\Sound;

final class FallDamageTest extends TestCase{
	private const FLOOR = 64.0;

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
		$this->h->set(3, 64, 3, VanillaBlocks::WATER());
		$this->h->set(-3, 64, -3, VanillaBlocks::HAY_BALE());
		$this->h->set(-3, 64, 3, VanillaBlocks::OAK_SLAB());
	}

	protected function tearDown() : void{
		$closed = new \ReflectionProperty(Entity::class, "closed");
		foreach($this->entities as $entity){
			$closed->setValue($entity, true);
		}
		$this->entities = [];
	}

	private function entity(float $x, float $y, float $z) : FallTestEntity{
		$entity = (new \ReflectionClass(FallTestEntity::class))->newInstanceWithoutConstructor();
		$set = static function(string $property, mixed $value) use ($entity) : void{
			(new \ReflectionProperty(Entity::class, $property))->setValue($entity, $value);
		};
		$set("id", 1);
		$set("location", new Location($x, $y, $z, $this->h->world, 0, 0));
		$set("size", new EntitySizeInfo(1.8, 0.6));
		$set("motion", new Vector3(0, 0, 0));
		(new \ReflectionProperty(Living::class, "effectManager"))->setValue($entity, new EffectManager($entity));
		$entity->boundingBox = new AxisAlignedBB($x - 0.3, $y, $z - 0.3, $x + 0.3, $y + 1.8, $z + 0.3);
		$this->entities[] = $entity;
		return $entity;
	}

	/**
	 * Feeds the per-tick deltas a Bedrock client reports until it lands.
	 */
	private function fall(FallTestEntity $entity, float $vy, ?float $terminalGlide = null) : void{
		$move = new \ReflectionMethod(Entity::class, "move");
		for($tick = 0; $tick < 600; ++$tick){
			$move->invoke($entity, 0.0, $terminalGlide !== null ? $terminalGlide : $vy, 0.0);
			if($entity->onGround){
				return;
			}
			$vy = ($vy - 0.08) * 0.98;
		}
		self::fail("entity never landed");
	}

	/** @return \Generator<string, array{float, float, float}> */
	public static function dropProvider() : \Generator{
		yield "walk off 3 blocks" => [3.0, 0.0, 0.0];
		yield "jump off 2 blocks" => [2.0, 0.42, 0.0];
		yield "walk off 4 blocks" => [4.0, 0.0, 1.0];
		yield "jump off 3 blocks" => [3.0, 0.42, 1.0];
		yield "walk off 23 blocks" => [23.0, 0.0, 20.0];
	}

	/** @dataProvider dropProvider */
	public function testDropsDealVanillaDamage(float $height, float $jumpVelocity, float $expected) : void{
		$entity = $this->entity(0.5, self::FLOOR + $height, 0.5);
		$this->fall($entity, $jumpVelocity);
		self::assertSame($expected, $entity->fallDamage);
		self::assertSame(0.0, $entity->fallDistance);
	}

	public function testRepeatedJumpsOnFlatGroundStayHarmless() : void{
		$entity = $this->entity(0.5, self::FLOOR, 0.5);
		$entity->onGround = true;
		for($i = 0; $i < 20; ++$i){
			$this->fall($entity, 0.42);
			self::assertSame(0.0, $entity->fallDistance);
		}
		self::assertSame(0.0, $entity->fallDamage);
	}

	public function testJumpBoostRaisesTheSafeDrop() : void{
		$entity = $this->entity(0.5, self::FLOOR + 4, 0.5);
		$entity->getEffects()->add(new EffectInstance(VanillaEffects::JUMP_BOOST(), 200, 0));
		$this->fall($entity, 0.0);
		self::assertSame(0.0, $entity->fallDamage);
	}

	public function testWaterCancelsTheFall() : void{
		$entity = $this->entity(3.5, self::FLOOR + 12, 3.5);
		$this->fall($entity, 0.0);
		self::assertSame(0.0, $entity->fallDamage);
	}

	public function testHayBaleCutsTheFall() : void{
		$entity = $this->entity(-2.5, self::FLOOR + 1 + 13, -2.5);
		$this->fall($entity, 0.0);
		self::assertSame(0.0, $entity->fallDamage); //13 * 0.2 - 3 < 0
	}

	public function testSlabLandingMeasuresFromTheSlabTop() : void{
		$entity = $this->entity(-2.5, self::FLOOR + 0.5 + 3.5, 3.5);
		$this->fall($entity, 0.0);
		self::assertSame(0.0, $entity->fallDamage);
	}

	public function testGlidingOnlyCountsTheFinalDive() : void{
		$entity = $this->entity(0.5, self::FLOOR + 40, 0.5);
		$entity->setGliding(true);
		$this->fall($entity, 0.0, -0.3);
		self::assertSame(0.0, $entity->fallDamage);

		$entity = $this->entity(0.5, self::FLOOR + 10, 0.5);
		$entity->setGliding(true);
		$this->fall($entity, 0.0, -0.9);
		self::assertSame(7.0, $entity->fallDamage);
	}
}

final class FallTestEntity extends Living implements NeverSavedWithChunkEntity{
	public float $fallDamage = 0.0;

	public function getName() : string{ return "Test"; }

	public function getNetworkTypeId() : string{ return "minecraft:zombie"; }

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(1.8, 0.6); }

	public function attack(EntityDamageEvent $source) : void{
		if($source->getCause() === EntityDamageEvent::CAUSE_FALL){
			$this->fallDamage += $source->getBaseDamage();
		}
	}

	public function broadcastSound(Sound $sound, ?array $targets = null) : void{}
}
