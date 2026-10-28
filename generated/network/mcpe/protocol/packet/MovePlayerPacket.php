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
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use quark\network\mcpe\protocol\Packet;
use quark\network\mcpe\protocol\types\MovePlayerTeleportData;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class MovePlayerPacket extends Packet{
	public const int ID = PacketId::MOVE_PLAYER;

	public const int MODE_NORMAL = 0;
	public const int MODE_RESET = 1;
	public const int MODE_TELEPORT = 2;
	public const int MODE_PITCH = 3;

	public function __construct(
		public int $playerRuntimeId,
		public Vector3 $position,
		public Vector2 $rotation,
		public float $yHeadRotation,
		public int $positionMode,
		public bool $onGround,
		public int $ridingRuntimeId,
		public ?MovePlayerTeleportData $teleportData,
		public int $tick,
	){}

	public function getId() : int{
		return self::ID;
	}
}
