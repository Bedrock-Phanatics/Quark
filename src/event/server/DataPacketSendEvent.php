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
use quark\network\mcpe\protocol\Packet;
use quark\network\mcpe\protocol\Packets;
use quark\utils\Utils;

/**
 * Called when packets are sent to network sessions.
 * The packets may be replaced, or their fields changed with Packet::setFields(), before they are encoded.
 */
class DataPacketSendEvent extends ServerEvent implements Cancellable{
	use CancellableTrait;

	/**
	 * @param NetworkSession[] $targets
	 * @param Packet[]         $packets
	 */
	public function __construct(
		private array $targets,
		private array $packets
	){}

	/**
	 * @return NetworkSession[]
	 */
	public function getTargets() : array{
		return $this->targets;
	}

	/**
	 * @return Packet[]
	 */
	public function getPackets() : array{
		return $this->packets;
	}

	/**
	 * @param Packet[] $packets
	 * @throws \InvalidArgumentException if a target does not accept one of the packets, or not yet
	 */
	public function setPackets(array $packets) : void{
		Utils::validateArrayValueType($packets, function(Packet $packet) : void{
			if(!isset(Packets::CLIENTBOUND[$packet->getId()])){
				throw new \InvalidArgumentException("Clients do not accept " . $packet->getName() . " packets");
			}
		});
		foreach($this->targets as $target){
			$target->checkSendable($packets);
		}
		$this->packets = $packets;
	}
}
