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

namespace quark\entity\effect;

use PHPUnit\Framework\TestCase;
use pocketmine\utils\Limits;
use quark\entity\EntitySizeInfo;
use quark\entity\Living;
use quark\event\entity\EntityEffectRemoveEvent;
use quark\event\entity\EntityRegainHealthEvent;

final class EffectManagerTest extends TestCase{

	private function entity() : EffectTestEntity{
		return new \ReflectionClass(EffectTestEntity::class)->newInstanceWithoutConstructor();
	}

	public function testSharedInstanceTracksDurationPerEntity() : void{
		$instance = new EffectInstance(VanillaEffects::NIGHT_VISION(), 100);
		$a = new EffectManager($this->entity());
		$b = new EffectManager($this->entity());
		$a->add($instance);
		$b->add($instance);

		$a->tick(30);

		self::assertSame(70, $a->getRemainingDuration(VanillaEffects::NIGHT_VISION()));
		self::assertSame(100, $b->getRemainingDuration(VanillaEffects::NIGHT_VISION()));
		self::assertSame(100, $instance->getDuration());
		self::assertSame($instance, $a->get(VanillaEffects::NIGHT_VISION()));
	}

	public function testEffectExpires() : void{
		$manager = new EffectManager($this->entity());
		$manager->add(new EffectInstance(VanillaEffects::NIGHT_VISION(), 40));

		self::assertTrue($manager->tick(39));
		self::assertSame(1, $manager->getRemainingDuration(VanillaEffects::NIGHT_VISION()));
		self::assertFalse($manager->tick(2));
		self::assertFalse($manager->has(VanillaEffects::NIGHT_VISION()));
		self::assertSame(0, $manager->getRemainingDuration(VanillaEffects::NIGHT_VISION()));
	}

	public function testInfiniteEffectWrapsAround() : void{
		$manager = new EffectManager($this->entity());
		$manager->add(new EffectInstance(VanillaEffects::NIGHT_VISION(), 10, infinite: true));

		$manager->tick(25);

		self::assertTrue($manager->has(VanillaEffects::NIGHT_VISION()));
		self::assertSame(Limits::INT32_MAX - 15, $manager->getRemainingDuration(VanillaEffects::NIGHT_VISION()));
	}

	public function testPeriodicEffectFollowsRemainingDuration() : void{
		$entity = $this->entity();
		$manager = new EffectManager($entity);
		$instance = new EffectInstance(VanillaEffects::REGENERATION(), 100, 1);
		$manager->add($instance);
		for($i = 0; $i < 100; ++$i){
			$manager->tick();
		}

		self::assertSame(4, $entity->heals);
		self::assertFalse($manager->has(VanillaEffects::REGENERATION()));
	}

	public function testEffectRemovedByItsOwnTickStaysRemoved() : void{
		$entity = $this->entity();
		$manager = new EffectManager($entity);
		$entity->onHeal = fn() => $manager->clear();
		$manager->add(new EffectInstance(VanillaEffects::REGENERATION(), 100, 1));

		$manager->tick();

		self::assertFalse($manager->has(VanillaEffects::REGENERATION()));
		self::assertSame(0, $manager->getRemainingDuration(VanillaEffects::REGENERATION()));
	}

	public function testReplacementComparesRemainingDuration() : void{
		$manager = new EffectManager($this->entity());
		$manager->add(new EffectInstance(VanillaEffects::NIGHT_VISION(), 100));
		$manager->tick(50);

		self::assertFalse($manager->add(new EffectInstance(VanillaEffects::NIGHT_VISION(), 40)));
		self::assertTrue($manager->add(new EffectInstance(VanillaEffects::NIGHT_VISION(), 60)));
		self::assertSame(60, $manager->getRemainingDuration(VanillaEffects::NIGHT_VISION()));
	}

	public function testExpiredRemovalCannotBeCancelled() : void{
		$instance = new EffectInstance(VanillaEffects::NIGHT_VISION(), 100);
		$entity = $this->entity();

		new EntityEffectRemoveEvent($entity, $instance)->cancel();

		$this->expectException(\LogicException::class);
		new EntityEffectRemoveEvent($entity, $instance, true)->cancel();
	}

	public function testWithDurationCopiesEverythingElse() : void{
		$instance = new EffectInstance(VanillaEffects::SPEED(), 100, 2, false, true, infinite: true);
		$copy = $instance->withDuration(20);

		self::assertSame(100, $instance->getDuration());
		self::assertSame(20, $copy->getDuration());
		self::assertSame(2, $copy->getAmplifier());
		self::assertFalse($copy->isVisible());
		self::assertTrue($copy->isAmbient());
		self::assertTrue($copy->isInfinite());
		self::assertSame($instance->getColor(), $copy->getColor());
	}

	public function testInvalidValuesAreRejected() : void{
		$this->expectException(\InvalidArgumentException::class);
		new EffectInstance(VanillaEffects::SPEED(), 100)->withDuration(-1);
	}
}

final class EffectTestEntity extends Living{
	public int $heals = 0;
	/** @phpstan-var (\Closure() : void)|null */
	public ?\Closure $onHeal = null;

	public function getName() : string{ return "Test"; }

	public static function getNetworkTypeId() : string{ return "minecraft:zombie"; }

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(1.8, 0.6); }

	public function getHealth() : float{ return 1.0; }

	public function getMaxHealth() : int{ return 20; }

	public function heal(EntityRegainHealthEvent $source) : void{
		++$this->heals;
		($this->onHeal)?->__invoke();
	}

	public function __destruct(){}
}
