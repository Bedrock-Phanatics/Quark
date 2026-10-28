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
final class RequestPermissionsPacket extends Packet{
	public const int ID = PacketId::REQUEST_PERMISSIONS;

	public const int FLAG_BUILD = 1;
	public const int FLAG_MINE = 2;
	public const int FLAG_DOORS_AND_SWITCHES = 4;
	public const int FLAG_OPEN_CONTAINERS = 8;
	public const int FLAG_ATTACK_PLAYERS = 16;
	public const int FLAG_ATTACK_MOBS = 32;
	public const int FLAG_OPERATOR = 64;
	public const int FLAG_TELEPORT = 128;

	public function __construct(
		public int $targetPlayerIdsRawId,
		public int $playerPermissionLevel,
		public int $customPermissionFlags,
	){}

	public function getId() : int{
		return self::ID;
	}
}
