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

namespace quark\data\bedrock\block\upgrade;

use quark\data\bedrock\LegacyToStringIdMap;
use quark\utils\SingletonTrait;
use Symfony\Component\Filesystem\Path;

final class LegacyBlockIdToStringIdMap extends LegacyToStringIdMap{
	use SingletonTrait;

	public function __construct(){
		parent::__construct(Path::join(\quark\BEDROCK_BLOCK_UPGRADE_SCHEMA_PATH, 'block_legacy_id_map.json'));
	}
}
