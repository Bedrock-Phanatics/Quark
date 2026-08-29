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

namespace quark;

use function define;
use function defined;
use function dirname;

// composer autoload doesn't use require_once and also pthreads can inherit things
if(defined('quark\_CORE_CONSTANTS_INCLUDED')){
	return;
}
define('quark\_CORE_CONSTANTS_INCLUDED', true);

define('quark\PATH', dirname(__DIR__) . '/');
define('quark\RESOURCE_PATH', dirname(__DIR__) . '/resources/');
define('quark\BEDROCK_DATA_PATH', dirname(__DIR__) . '/vendor/axolotl-pm/bedrock-data/');
define('quark\LOCALE_DATA_PATH', dirname(__DIR__) . '/resources/translations/');
define('quark\BEDROCK_BLOCK_UPGRADE_SCHEMA_PATH', dirname(__DIR__) . '/vendor/axolotl-pm/bedrock-block-upgrade-schema/');
define('quark\BEDROCK_ITEM_UPGRADE_SCHEMA_PATH', dirname(__DIR__) . '/vendor/axolotl-pm/bedrock-item-upgrade-schema/');
define('quark\COMPOSER_AUTOLOADER_PATH', dirname(__DIR__) . '/vendor/autoload.php');
