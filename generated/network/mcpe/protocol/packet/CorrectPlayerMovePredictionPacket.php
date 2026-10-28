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

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class CorrectPlayerMovePredictionPacket extends Packet{
	public const int ID = PacketId::CORRECT_PLAYER_MOVE_PREDICTION;

	public const int PREDICTION_TYPE_PLAYER = 0;
	public const int PREDICTION_TYPE_VEHICLE = 1;

	public function __construct(
		public int $predictionType,
		public Vector3 $pos,
		public Vector3 $posDelta,
		public Vector2 $rotation,
		public ?float $vehicleAngularVelocity,
		public bool $onGround,
		public int $tick,
	){}

	public function getId() : int{
		return self::ID;
	}
}
