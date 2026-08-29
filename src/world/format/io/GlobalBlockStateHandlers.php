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

namespace quark\world\format\io;

use quark\data\bedrock\block\BlockStateData;
use quark\data\bedrock\block\BlockTypeNames;
use quark\data\bedrock\block\convert\BlockObjectToStateSerializer;
use quark\data\bedrock\block\convert\BlockSerializerDeserializerRegistrar;
use quark\data\bedrock\block\convert\BlockStateToObjectDeserializer;
use quark\data\bedrock\block\convert\VanillaBlockMappings;
use quark\data\bedrock\block\upgrade\BlockDataUpgrader;
use quark\data\bedrock\block\upgrade\BlockIdMetaUpgrader;
use quark\data\bedrock\block\upgrade\BlockStateUpgrader;
use quark\data\bedrock\block\upgrade\BlockStateUpgradeSchemaUtils;
use quark\data\bedrock\block\upgrade\LegacyBlockIdToStringIdMap;
use quark\utils\Filesystem;
use Symfony\Component\Filesystem\Path;
use const PHP_INT_MAX;
use const quark\BEDROCK_BLOCK_UPGRADE_SCHEMA_PATH;

/**
 * Provides global access to blockstate serializers for all world providers.
 * TODO: Get rid of this. This is necessary to enable plugins to register custom serialize/deserialize handlers, and
 * also because we can't break BC of WorldProvider before PM5. While this is a sucky hack, it provides meaningful
 * benefits for now.
 */
final class GlobalBlockStateHandlers{
	private static ?BlockDataUpgrader $blockDataUpgrader = null;

	private static ?BlockStateData $unknownBlockStateData = null;

	private static ?BlockSerializerDeserializerRegistrar $registrar = null;

	public static function getRegistrar() : BlockSerializerDeserializerRegistrar{
		if(self::$registrar === null){
			$deserializer = new BlockStateToObjectDeserializer();
			$serializer = new BlockObjectToStateSerializer();
			self::$registrar = new BlockSerializerDeserializerRegistrar($deserializer, $serializer);
			VanillaBlockMappings::init(self::$registrar);
		}
		return self::$registrar;
	}

	public static function getDeserializer() : BlockStateToObjectDeserializer{
		return self::getRegistrar()->deserializer;
	}

	public static function getSerializer() : BlockObjectToStateSerializer{
		return self::getRegistrar()->serializer;
	}

	public static function getUpgrader() : BlockDataUpgrader{
		if(self::$blockDataUpgrader === null){
			$blockStateUpgrader = new BlockStateUpgrader(BlockStateUpgradeSchemaUtils::loadSchemas(
				Path::join(BEDROCK_BLOCK_UPGRADE_SCHEMA_PATH, 'nbt_upgrade_schema'),
				PHP_INT_MAX
			));
			self::$blockDataUpgrader = new BlockDataUpgrader(
				BlockIdMetaUpgrader::loadFromString(
					Filesystem::fileGetContents(Path::join(
						BEDROCK_BLOCK_UPGRADE_SCHEMA_PATH,
						'id_meta_to_nbt/1.12.0.bin'
					)),
					LegacyBlockIdToStringIdMap::getInstance(),
					$blockStateUpgrader
				),
				$blockStateUpgrader
			);
		}

		return self::$blockDataUpgrader;
	}

	public static function getUnknownBlockStateData() : BlockStateData{
		return self::$unknownBlockStateData ??= BlockStateData::current(BlockTypeNames::INFO_UPDATE, []);
	}
}
