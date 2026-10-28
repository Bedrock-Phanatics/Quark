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
final class PlayStatusPacket extends Packet{
	public const int ID = PacketId::PLAY_STATUS;

	public const int LOGIN_SUCCESS = 0;
	public const int LOGIN_FAILED_CLIENT = 1;
	public const int LOGIN_FAILED_SERVER = 2;
	public const int PLAYER_SPAWN = 3;
	public const int LOGIN_FAILED_INVALID_TENANT = 4;
	public const int LOGIN_FAILED_VANILLA_EDU = 5;
	public const int LOGIN_FAILED_EDU_VANILLA = 6;
	public const int LOGIN_FAILED_SERVER_FULL = 7;
	public const int LOGIN_FAILED_EDITOR_VANILLA = 8;
	public const int LOGIN_FAILED_VANILLA_EDITOR = 9;

	public function __construct(
		public int $status,
	){}

	public function getId() : int{
		return self::ID;
	}
}
