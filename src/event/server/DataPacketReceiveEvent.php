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

namespace quark\event\server;

use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ServerboundPacket;

class DataPacketReceiveEvent extends ServerEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		private NetworkSession $origin,
		private ServerboundPacket $packet
	){}

	public function getPacket() : ServerboundPacket{
		return $this->packet;
	}

	public function getOrigin() : NetworkSession{
		return $this->origin;
	}
}
