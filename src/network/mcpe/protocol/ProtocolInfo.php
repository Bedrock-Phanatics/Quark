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

namespace quark\network\mcpe\protocol;

/**
 * The game version Quark speaks. Startup refuses an ext-bedwire built for any other protocol.
 */
final class ProtocolInfo{
	public const int CURRENT_PROTOCOL = 2193;
	/** Display version shown in the server logs, as on the game's home screen. */
	public const string MINECRAFT_VERSION = "v26.50";
	/** Version sent on the network for client side compatibility checks, which may differ from the display version. */
	public const string MINECRAFT_VERSION_NETWORK = "1.26.50";

	private function __construct(){
		//NOOP
	}
}
