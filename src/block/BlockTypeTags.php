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

namespace quark\block;

final class BlockTypeTags{
	private const PREFIX = "quark:";

	public const DIRT = self::PREFIX . "dirt";
	public const MUD = self::PREFIX . "mud";
	public const SAND = self::PREFIX . "sand";
	public const POTTABLE_PLANTS = self::PREFIX . "pottable";
	public const FIRE = self::PREFIX . "fire";
	public const HANGING_SIGN = self::PREFIX . "hanging_sign";
	public const NYLIUM = self::PREFIX . "nylium";
	public const HUGE_FUNGUS_REPLACEABLE = self::PREFIX . "huge_fungus_replaceable";
	public const BAMBOO_MOSAIC = self::PREFIX . "bamboo_mosaic";
}
