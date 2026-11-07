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
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use quark\world\sound\EntityLongFallSound;
use quark\world\sound\EntityShortFallSound;

final class EntityNetworkTypeTest extends TestCase{

	public function testSameClassReportsDifferentTypePerInstance() : void{
		$sheep = self::create("minecraft:sheep");
		$cow = self::create("minecraft:cow");
		self::assertSame("minecraft:sheep", $sheep->getNetworkTypeId());
		self::assertSame("minecraft:cow", $cow->getNetworkTypeId());

		foreach([$sheep, $cow] as $entity){
			foreach([new EntityLongFallSound($entity), new EntityShortFallSound($entity)] as $sound){
				$packet = $sound->encode(Vector3::zero())[0];
				self::assertInstanceOf(LevelSoundEventPacket::class, $packet);
				self::assertSame($entity->getNetworkTypeId(), $packet->entityType);
			}
		}
	}

	private static function create(string $type) : TypeTestEntity{
		$entity = new \ReflectionClass(TypeTestEntity::class)->newInstanceWithoutConstructor();
		$entity->type = $type;
		new \ReflectionProperty(Entity::class, "id")->setValue($entity, 1);
		return $entity;
	}
}

final class TypeTestEntity extends Entity{
	public string $type;

	public function __destruct(){}

	public function getNetworkTypeId() : string{ return $this->type; }

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(1, 1); }

	protected function getInitialDragMultiplier() : float{ return 0; }

	protected function getInitialGravity() : float{ return 0; }
}
