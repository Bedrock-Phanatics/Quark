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

namespace quark\event\player;

use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\lang\Translatable;
use quark\player\Player;

/**
 * Called when a player attempts to be transferred to another server, e.g. by using /transferserver.
 */
class PlayerTransferEvent extends PlayerEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Player $player,
		protected string $address,
		protected int $port,
		protected Translatable|string $message
	){
		$this->player = $player;
	}

	/**
	 * Returns the destination server address. This could be an IP or a domain name.
	 */
	public function getAddress() : string{
		return $this->address;
	}

	/**
	 * Sets the destination server address.
	 */
	public function setAddress(string $address) : void{
		$this->address = $address;
	}

	/**
	 * Returns the destination server port.
	 */
	public function getPort() : int{
		return $this->port;
	}

	/**
	 * Sets the destination server port.
	 */
	public function setPort(int $port) : void{
		$this->port = $port;
	}

	/**
	 * Returns the disconnect reason shown in the server log and on the console.
	 */
	public function getMessage() : Translatable|string{
		return $this->message;
	}

	/**
	 * Sets the disconnect reason shown in the server log and on the console.
	 */
	public function setMessage(Translatable|string $message) : void{
		$this->message = $message;
	}
}
