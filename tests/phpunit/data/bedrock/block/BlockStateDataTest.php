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

use PHPUnit\Framework\TestCase;
use quark\data\bedrock\block\BlockStateData;
use quark\data\bedrock\block\upgrade\BlockStateUpgradeSchemaUtils;
use Symfony\Component\Filesystem\Path;
use function sprintf;
use const PHP_INT_MAX;
use const quark\BEDROCK_BLOCK_UPGRADE_SCHEMA_PATH;

final class BlockStateDataTest extends TestCase{

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
