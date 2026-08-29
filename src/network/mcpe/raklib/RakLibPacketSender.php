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

namespace quark\network\mcpe\raklib;

use quark\network\mcpe\PacketSender;

class RakLibPacketSender implements PacketSender{
	private bool $closed = false;

	public function __construct(
		private int $sessionId,
		private RakLibInterface $handler
	){}

	public function send(string $payload, bool $immediate, ?int $receiptId) : void{
		if(!$this->closed){
			$this->handler->putPacket($this->sessionId, $payload, $immediate, $receiptId);
		}
	}

	public function close(string $reason = "unknown reason") : void{
		if(!$this->closed){
			$this->closed = true;
			$this->handler->close($this->sessionId);
		}
	}
}
