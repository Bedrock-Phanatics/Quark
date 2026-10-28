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
final class BossEventPacket extends Packet{
	public const int ID = PacketId::BOSS_EVENT;

	public const int TYPE_SHOW = 0;
	public const int TYPE_REGISTER_PLAYER = 1;
	public const int TYPE_HIDE = 2;
	public const int TYPE_UNREGISTER_PLAYER = 3;
	public const int TYPE_HEALTH_PERCENT = 4;
	public const int TYPE_TITLE = 5;
	public const int TYPE_PROPERTIES = 6;
	public const int TYPE_TEXTURE = 7;
	public const int TYPE_QUERY = 8;

	public function __construct(
		public int $targetActorId,
		public int $eventType,
		public string $name,
		public string $filteredName,
		public float $healthPercent,
		public int $color,
		public int $overlay,
	){}

	public function getId() : int{
		return self::ID;
	}
}
