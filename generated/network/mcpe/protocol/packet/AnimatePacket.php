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
final class AnimatePacket extends Packet{
	public const int ID = PacketId::ANIMATE;

	public const int ACTION_SWING_ARM = 1;
	public const int ACTION_STOP_SLEEP = 3;
	public const int ACTION_CRITICAL_HIT = 4;
	public const int ACTION_MAGICAL_CRITICAL_HIT = 5;

	public function __construct(
		public int $action,
		public int $targetActorRuntimeId,
		public float $data,
		public ?string $swingSource = null,
	){}

	public function getId() : int{
		return self::ID;
	}
}
