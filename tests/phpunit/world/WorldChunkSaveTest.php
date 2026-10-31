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

namespace quark\world;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;
use quark\entity\Entity;
use quark\entity\EntitySizeInfo;

final class WorldChunkSaveTest extends TestCase{

	private static function entity(bool $flagged, bool $savable = true) : ChunkSaveTestEntity{
		$entity = new \ReflectionClass(ChunkSaveTestEntity::class)->newInstanceWithoutConstructor();
		$entity->flagged = $flagged;
		$entity->savable = $savable;
		return $entity;
	}

	public function testFlaggedEntitiesAreNotSaved() : void{
		//an orb merged away this tick is flagged but not closed yet, and saving it would duplicate its experience
		$merged = self::entity(true);
		$saved = new \ReflectionMethod(World::class, "saveChunkEntities")->invoke(null, [self::entity(false), $merged, self::entity(false, false)]);
		self::assertIsArray($saved);
		self::assertCount(1, $saved);
		self::assertSame(0, $merged->saves);
	}
}

final class ChunkSaveTestEntity extends Entity{
	public bool $flagged = false;
	public bool $savable = true;
	public int $saves = 0;

	public function __destruct(){}

	public static function getNetworkTypeId() : string{ return "test"; }

	protected function getInitialSizeInfo() : EntitySizeInfo{ return new EntitySizeInfo(1, 1); }

	protected function getInitialDragMultiplier() : float{ return 0; }

	protected function getInitialGravity() : float{ return 0; }

	public function isFlaggedForDespawn() : bool{ return $this->flagged; }

	public function canSaveWithChunk() : bool{ return $this->savable; }

	public function saveNBT() : CompoundTag{
		++$this->saves;
		return new CompoundTag();
	}
}
