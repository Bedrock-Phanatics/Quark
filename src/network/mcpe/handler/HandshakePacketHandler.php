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

namespace quark\network\mcpe\handler;

use quark\network\mcpe\protocol\Packets;

/**
 * Handler responsible for awaiting client response from crypto handshake.
 *
 * @phpstan-import-type ClientToServerHandshakeFields from Packets
 */
class HandshakePacketHandler extends PacketHandler{
	/**
	 * @phpstan-param \Closure() : void $onHandshakeCompleted
	 */
	public function __construct(private \Closure $onHandshakeCompleted){}

	/**
	 * @phpstan-param ClientToServerHandshakeFields $packet
	 */
	public function handleClientToServerHandshake(array $packet) : bool{
		($this->onHandshakeCompleted)();
		return true;
	}
}
