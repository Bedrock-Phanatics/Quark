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
final class NpcRequestPacket extends Packet{
	public const int ID = PacketId::NPC_REQUEST;

	public const int REQUEST_SET_ACTIONS = 0;
	public const int REQUEST_EXECUTE_ACTION = 1;
	public const int REQUEST_EXECUTE_CLOSING_COMMANDS = 2;
	public const int REQUEST_SET_NAME = 3;
	public const int REQUEST_SET_SKIN = 4;
	public const int REQUEST_SET_INTERACTION_TEXT = 5;
	public const int REQUEST_EXECUTE_OPENING_COMMANDS = 6;

	public function __construct(
		public int $npcRuntimeId,
		public int $requestType,
		public string $actions,
		public int $actionIndex,
		public string $sceneName,
	){}

	public function getId() : int{
		return self::ID;
	}
}
