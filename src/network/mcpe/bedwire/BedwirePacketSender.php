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

namespace quark\network\mcpe\bedwire;

use quark\network\mcpe\NativeBatchPacketSender;

/**
 * @internal
 */
final class BedwirePacketSender implements NativeBatchPacketSender{
	private bool $closed = false;

	public function __construct(
		private int $sessionId,
		private BedwireInterface $interface
	){}

	public function send(string $payload, bool $immediate, ?int $receiptId) : void{
		$this->sendBatch($payload, 0);
	}

	public function sendBatch(string $batch, int $offset) : void{
		if(!$this->closed){
			$this->interface->putBatch($this->sessionId, $batch, $offset);
		}
	}

	public function sendPackets(array $packets) : bool{
		return $this->closed || $this->interface->putPackets($this->sessionId, $packets);
	}

	public function acceptLogin(string $clientPublicKeyDer) : void{
		if(!$this->closed){
			$this->interface->acceptLogin($this->sessionId, $clientPublicKeyDer);
		}
	}

	public function close(string $reason = "unknown reason") : void{
		if(!$this->closed){
			$this->closed = true;
			$this->interface->close($this->sessionId);
		}
	}
}
