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
final class SetTitlePacket extends Packet{
	public const int ID = PacketId::SET_TITLE;

	public const int TYPE_CLEAR_TITLE = 0;
	public const int TYPE_RESET_TITLE = 1;
	public const int TYPE_SET_TITLE = 2;
	public const int TYPE_SET_SUBTITLE = 3;
	public const int TYPE_SET_ACTIONBAR_MESSAGE = 4;
	public const int TYPE_SET_ANIMATION_TIMES = 5;
	public const int TYPE_SET_TITLE_JSON = 6;
	public const int TYPE_SET_SUBTITLE_JSON = 7;
	public const int TYPE_SET_ACTIONBAR_MESSAGE_JSON = 8;

	public function __construct(
		public int $titleType,
		public string $titleText,
		public int $fadeInTime,
		public int $stayTime,
		public int $fadeOutTime,
		public string $xuid,
		public string $platformOnlineId,
		public string $filteredTitleMessage,
	){}

	public function getId() : int{
		return self::ID;
	}
}
