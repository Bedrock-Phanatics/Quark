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

use quark\data\bedrock\item\BlockItemIdMap;
use quark\data\bedrock\item\ItemDeserializer;
use quark\data\bedrock\item\ItemSerializer;
use quark\data\bedrock\item\upgrade\ItemDataUpgrader;
use quark\data\bedrock\item\upgrade\ItemIdMetaUpgrader;
use quark\data\bedrock\item\upgrade\ItemIdMetaUpgradeSchemaUtils;
use quark\data\bedrock\item\upgrade\LegacyItemIdToStringIdMap;
use quark\data\bedrock\item\upgrade\R12ItemIdToBlockIdMap;
use quark\network\mcpe\convert\TypeConverter;
use Symfony\Component\Filesystem\Path;
use const PHP_INT_MAX;
use const quark\BEDROCK_ITEM_UPGRADE_SCHEMA_PATH;

final class GlobalItemDataHandlers{
	private static ?ItemSerializer $itemSerializer = null;

	private static ?ItemDeserializer $itemDeserializer = null;

	private static ?ItemDataUpgrader $itemDataUpgrader = null;

	public static function getSerializer() : ItemSerializer{
		return self::$itemSerializer ??= new ItemSerializer(GlobalBlockStateHandlers::getSerializer());
	}

	public static function getDeserializer() : ItemDeserializer{
		return self::$itemDeserializer ??= new ItemDeserializer(GlobalBlockStateHandlers::getDeserializer());
	}

	public static function getUpgrader() : ItemDataUpgrader{
		return self::$itemDataUpgrader ??= new ItemDataUpgrader(
			new ItemIdMetaUpgrader(ItemIdMetaUpgradeSchemaUtils::loadSchemas(Path::join(BEDROCK_ITEM_UPGRADE_SCHEMA_PATH, 'id_meta_upgrade_schema'), PHP_INT_MAX)),
			LegacyItemIdToStringIdMap::getInstance(),
			R12ItemIdToBlockIdMap::getInstance(),
			GlobalBlockStateHandlers::getUpgrader(),
			BlockItemIdMap::getInstance(),
			TypeConverter::getInstance()->getBlockTranslator()->getBlockStateDictionary()
		);
	}
}
