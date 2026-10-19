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

namespace quark\network\mcpe\nethernet;

use pmmp\thread\ThreadSafe;

/**
 * @internal
 */
final class NetherNetIceServer extends ThreadSafe{

	/**
	 * Username and password are empty for STUN server
	 */
	public function __construct(
		private bool $turn,
		private string $host,
		private int $port,
		private string $username,
		private string $password
	){}

	public function isTurn() : bool{ return $this->turn; }

	public function getHost() : string{ return $this->host; }

	public function getPort() : int{ return $this->port; }

	public function getUsername() : string{ return $this->username; }

	public function getPassword() : string{ return $this->password; }
}
