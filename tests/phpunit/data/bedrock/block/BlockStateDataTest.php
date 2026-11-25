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

namespace quark\data\bedrock\block;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use quark\data\bedrock\block\upgrade\BlockStateUpgradeSchemaUtils;
use quark\VersionInfo;
use Symfony\Component\Filesystem\Path;
use function sprintf;
use const PHP_INT_MAX;
use const quark\BEDROCK_BLOCK_UPGRADE_SCHEMA_PATH;

final class BlockStateDataTest extends TestCase{

	private static function nbt() : CompoundTag{
		return BlockStateData::current("minecraft:dirt", ["dirt_type" => new IntTag(0)])->toVanillaNbt();
	}

	/**
	 * @return \Generator<string, array{list<string>}>
	 */
	public static function dataVersionTags() : \Generator{
		yield "vanilla" => [[]];
		yield "PocketMine-MP" => [[VersionInfo::TAG_WORLD_DATA_VERSION]];
		yield "Quark 6.0" => [[VersionInfo::TAG_LEGACY_WORLD_DATA_VERSION]];
		yield "both" => [[VersionInfo::TAG_WORLD_DATA_VERSION, VersionInfo::TAG_LEGACY_WORLD_DATA_VERSION]];
	}

	/**
	 * @param string[] $tags
	 * @phpstan-param list<string> $tags
	 */
	#[DataProvider("dataVersionTags")]
	public function testDataVersionTagsAreAccepted(array $tags) : void{
		$nbt = self::nbt();
		foreach($tags as $tag){
			$nbt->setLong($tag, VersionInfo::WORLD_DATA_VERSION);
		}
		$data = BlockStateData::fromNbt($nbt);
		self::assertTrue($data->equals(BlockStateData::fromNbt(self::nbt())));
		self::assertSame(BlockStateData::CURRENT_VERSION, $data->getVersion());
	}

	public function testSavedDataUsesThePocketMineTag() : void{
		$nbt = BlockStateData::fromNbt(self::nbt())->toNbt();
		self::assertSame(VersionInfo::WORLD_DATA_VERSION, $nbt->getLong("PMMPDataVersion"));
		self::assertNull($nbt->getTag(VersionInfo::TAG_LEGACY_WORLD_DATA_VERSION));
	}

	public function testUnknownKeysAreRejected() : void{
		$this->expectException(BlockStateDeserializeException::class);
		$this->expectExceptionMessage("Unexpected extra keys: foo");
		BlockStateData::fromNbt(self::nbt()->setLong(VersionInfo::TAG_WORLD_DATA_VERSION, 1)->setLong("foo", 1));
	}

	public function testCurrentVersion() : void{
		foreach(BlockStateUpgradeSchemaUtils::loadSchemas(
			Path::join(BEDROCK_BLOCK_UPGRADE_SCHEMA_PATH, 'nbt_upgrade_schema'),
				PHP_INT_MAX
		) as $schema){
			$expected = BlockStateData::CURRENT_VERSION;
			$actual = $schema->getVersionId();
			self::assertLessThanOrEqual($expected, $actual, sprintf(
				"Schema version %d (%d.%d.%d.%d) is newer than the current version %d (%d.%d.%d.%d)",
				$actual,
				($actual >> 24) & 0xff,
				($actual >> 16) & 0xff,
				($actual >> 8) & 0xff,
				$actual & 0xff,
				$expected,
				($expected >> 24) & 0xff,
				($expected >> 16) & 0xff,
				($expected >> 8) & 0xff,
				$expected & 0xff
			));
		}
	}
}
