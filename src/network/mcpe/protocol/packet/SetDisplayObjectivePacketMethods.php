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

namespace quark\network\mcpe\protocol\packet;

trait SetDisplayObjectivePacketMethods{

	/**
	 * @param int $sortOrder a SORT_ORDER_* constant
	 */
	public static function sidebar(string $objectiveName, string $displayName, int $sortOrder = self::SORT_ORDER_ASCENDING) : self{
		return new self(self::DISPLAY_SLOT_SIDEBAR, $objectiveName, $displayName, sortOrder: $sortOrder);
	}

	/**
	 * @param int $sortOrder a SORT_ORDER_* constant
	 */
	public static function list(string $objectiveName, string $displayName, int $sortOrder = self::SORT_ORDER_ASCENDING) : self{
		return new self(self::DISPLAY_SLOT_LIST, $objectiveName, $displayName, sortOrder: $sortOrder);
	}

	public static function belowName(string $objectiveName, string $displayName) : self{
		return new self(self::DISPLAY_SLOT_BELOW_NAME, $objectiveName, $displayName);
	}
}
