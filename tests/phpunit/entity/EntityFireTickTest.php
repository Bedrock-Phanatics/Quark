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
use quark\Server;
use function range;

final class EntityFireTickTest extends TestCase{
	private int $tick = 0;
	private FireTickTestEntity $entity;

	protected function setUp() : void{
		$server = $this->createMock(Server::class);
		$server->method("getTick")->willReturnCallback(fn() => $this->tick);
		$this->entity = new \ReflectionClass(FireTickTestEntity::class)->newInstanceWithoutConstructor();
		new \ReflectionProperty(Entity::class, "server")->setValue($this->entity, $server);
	}

	/**
	 * Updates the entity like the world does, at the given ticks, and returns the ticks it took fire damage on.
	 *
	 * @param int[] $updateTicks
	 * @return int[]
	 */
	private function burn(int $lastUpdate, array $updateTicks) : array{
		$damageTicks = [];
		foreach($updateTicks as $tick){
			$this->tick = $tick;
			$damage = $this->entity->damage;
			if($this->entity->isOnFire()){
				new \ReflectionMethod(Entity::class, "doOnFireTick")->invoke($this->entity, $tick - $lastUpdate);
			}
			if($this->entity->damage > $damage){
				$damageTicks[] = $tick;
			}
			$lastUpdate = $tick;
		}
		return $damageTicks;
	}

	public function testBurnsEveryTwentyTicks() : void{
		$this->tick = 100;
		$this->entity->setOnFire(8);
		self::assertSame([120, 140, 160, 180, 200, 220, 240, 260], $this->burn(100, range(101, 270)));
		self::assertFalse($this->entity->isOnFire());
	}

	/**
	 * @phpstan-return \Generator<string, array{int}, void, void>
	 */
	public static function skippedTicksProvider() : \Generator{
		yield "20 tick interval" => [20];
		yield "40 tick interval" => [40];
	}

	/**
	 * @dataProvider skippedTicksProvider
	 */
	public function testTicksSkippedBeforeCatchingFireDontBurn(int $interval) : void{
		$this->tick = 99;
		$this->entity->setOnFire(8);
		//the entity last ticked an interval ago, and is ticked every tick from now on since it's burning
		self::assertSame([119, 139, 159, 179, 199, 219, 239, 259], $this->burn(100 - $interval, range(100, 270)));
		self::assertFalse($this->entity->isOnFire());
	}

	public function testLongGapStillDealsDamage() : void{
		$this->tick = 10;
		$this->entity->setOnFire(8);
		self::assertSame([1000], $this->burn(10, [1000]));
		self::assertFalse($this->entity->isOnFire());
	}
}

final class FireTickTestEntity extends Entity{
	public int $damage = 0;

	public function __destruct(){}

	public static function getNetworkTypeId() : string{ return "test"; }

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(1, 1); }

	protected function getInitialDragMultiplier() : float{ return 0; }

	protected function getInitialGravity() : float{ return 0; }

	protected function dealFireDamage() : void{
		++$this->damage;
	}
}
