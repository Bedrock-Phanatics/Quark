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

use Bedwire\PacketId;
use quark\network\mcpe\protocol\Packet;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class SetDisplayObjectivePacket extends Packet{
	public const int ID = PacketId::SET_DISPLAY_OBJECTIVE;

	public const string DISPLAY_SLOT_LIST = 'list';
	public const string DISPLAY_SLOT_SIDEBAR = 'sidebar';
	public const string DISPLAY_SLOT_BELOW_NAME = 'belowname';
	public const int SORT_ORDER_ASCENDING = 0;
	public const int SORT_ORDER_DESCENDING = 1;

	public function __construct(
		public string $displaySlotName,
		public string $objectiveName,
		public string $objectiveDisplayName,
		public string $criteriaName,
		public int $sortOrder,
	){}

	public function getId() : int{
		return self::ID;
	}
}
