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
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\MobEffectPacket;
use pocketmine\utils\Limits;
use quark\entity\Entity;
use quark\entity\EntitySizeInfo;
use quark\entity\Living;
use quark\entity\Location;
use quark\entity\NeverSavedWithChunkEntity;
use quark\event\entity\EntityEffectAddEvent;
use quark\event\entity\EntityEffectRemoveEvent;
use quark\event\entity\EntityRegainHealthEvent;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\RegisteredListener;
use quark\math\Vector3;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\PacketBroadcaster;
use quark\network\mcpe\StandardEntityEventBroadcaster;
use quark\plugin\Plugin;
use quark\pulse\Pulse;
use quark\world\World;
use function array_map;

final class EffectManagerTest extends TestCase{
	private Plugin $plugin;

	private function entity() : EffectTestEntity{
		$entity = new \ReflectionClass(EffectTestEntity::class)->newInstanceWithoutConstructor();
		new \ReflectionProperty(Entity::class, "id")->setValue($entity, 7);
		$world = $this->createMock(World::class);
		$world->method("isLoaded")->willReturn(true);
		new \ReflectionProperty(Entity::class, "location")->setValue($entity, new Location(0, 64, 0, $world, 0, 0));
		new \ReflectionProperty(Entity::class, "motion")->setValue($entity, Vector3::zero());
		new \ReflectionProperty(Living::class, "effectManager")->setValue($entity, new EffectManager($entity));
		return $entity;
	}

	/** @param \Closure(EntityEffectAddEvent) : void $callback */
	private function listen(\Closure $callback) : void{
		$this->plugin = $this->createMock(Plugin::class);
		HandlerListManager::global()->getListFor(EntityEffectAddEvent::class)->register(new RegisteredListener($callback, EventPriority::NORMAL, $this->plugin, true, Pulse::zone("effects.test")));
	}

	protected function tearDown() : void{
		if(isset($this->plugin)){
			HandlerListManager::global()->unregisterAll($this->plugin);
		}
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

	public function testAddEventCanReplaceEffect() : void{
		$this->listen(fn(EntityEffectAddEvent $e) => $e->setEffect($e->getEffect()->withDuration(200)));
		$manager = new EffectManager($this->entity());

		self::assertTrue($manager->add(new EffectInstance(VanillaEffects::NIGHT_VISION(), 100)));

		self::assertSame(200, $manager->getRemainingDuration(VanillaEffects::NIGHT_VISION()));
		self::assertSame(200, $manager->get(VanillaEffects::NIGHT_VISION())?->getDuration());
	}

	public function testAddEventCannotForceWeakerReplacement() : void{
		$manager = new EffectManager($this->entity());
		$strong = new EffectInstance(VanillaEffects::NIGHT_VISION(), 100);
		$manager->add($strong);
		$this->listen(function(EntityEffectAddEvent $e) : void{
			$e->uncancel();
			$e->setEffect($e->getEffect()->withDuration(5));
		});

		self::assertFalse($manager->add(new EffectInstance(VanillaEffects::NIGHT_VISION(), 60)));
		self::assertSame($strong, $manager->get(VanillaEffects::NIGHT_VISION()));
		self::assertSame(100, $manager->getRemainingDuration(VanillaEffects::NIGHT_VISION()));
	}

	public function testAddEventCannotChangeEffectType() : void{
		$event = new EntityEffectAddEvent($this->entity(), new EffectInstance(VanillaEffects::NIGHT_VISION(), 100));

		$this->expectException(\InvalidArgumentException::class);
		$event->setEffect(new EffectInstance(VanillaEffects::SPEED(), 100));
	}

	public function testPacketAndSaveUseRemainingDuration() : void{
		$entity = $this->entity();
		$manager = $entity->getEffects();
		$manager->add(new EffectInstance(VanillaEffects::NIGHT_VISION(), 100));
		$manager->add(new EffectInstance(VanillaEffects::INVISIBILITY(), 10, infinite: true));
		$manager->tick(30);

		$sent = [];
		$packets = $this->createMock(PacketBroadcaster::class);
		$packets->method("broadcastPackets")->willReturnCallback(function(array $recipients, array $batch) use (&$sent) : void{
			foreach($batch as $packet){
				$sent[] = $packet;
			}
		});
		$broadcaster = new StandardEntityEventBroadcaster($packets, new \ReflectionClass(TypeConverter::class)->newInstanceWithoutConstructor());
		foreach($manager->all() as $effect){
			$broadcaster->onEntityEffectAdded([], $entity, $effect, false);
		}

		self::assertContainsOnlyInstancesOf(MobEffectPacket::class, $sent);
		self::assertSame([70, -1], array_map(fn(MobEffectPacket $p) => $p->duration, $sent));

		$saved = [];
		foreach($entity->saveNBT()->getListTag("ActiveEffects") ?? [] as $tag){
			self::assertInstanceOf(CompoundTag::class, $tag);
			$saved[] = $tag->getInt("Duration");
		}
		self::assertSame([70, -1], $saved);
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

final class EffectTestEntity extends Living implements NeverSavedWithChunkEntity{
	public int $heals = 0;
	/** @phpstan-var (\Closure() : void)|null */
	public ?\Closure $onHeal = null;

	public function getName() : string{ return "Test"; }

	public function getNetworkTypeId() : string{ return "minecraft:zombie"; }

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(1.8, 0.6); }

	public function getHealth() : float{ return 1.0; }

	public function getMaxHealth() : int{ return 20; }

	public function heal(EntityRegainHealthEvent $source) : void{
		++$this->heals;
		($this->onHeal)?->__invoke();
	}

	public function __destruct(){}
}
