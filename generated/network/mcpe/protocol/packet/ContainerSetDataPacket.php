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
final class ContainerSetDataPacket extends Packet{
	public const int ID = PacketId::CONTAINER_SET_DATA;

	public const int PROPERTY_FURNACE_SMELT_PROGRESS = 0;
	public const int PROPERTY_FURNACE_REMAINING_FUEL_TIME = 1;
	public const int PROPERTY_FURNACE_MAX_FUEL_TIME = 2;
	public const int PROPERTY_FURNACE_STORED_XP = 3;
	public const int PROPERTY_FURNACE_FUEL_AUX = 4;
	public const int PROPERTY_BREWING_STAND_BREW_TIME = 0;
	public const int PROPERTY_BREWING_STAND_FUEL_AMOUNT = 1;
	public const int PROPERTY_BREWING_STAND_FUEL_TOTAL = 2;

	public function __construct(
		public int $containerId,
		public int $id,
		public int $value,
	){}

	public function getId() : int{
		return self::ID;
	}
}
