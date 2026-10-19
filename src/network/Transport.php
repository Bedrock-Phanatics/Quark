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

namespace quark\network;

use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\EntityEventBroadcaster;
use quark\network\mcpe\PacketBroadcaster;

/**
 * Represents a network transport (e.g. RakNet, NetherNet) that provides network interfaces for player connections.
 */
interface Transport{

	/**
	 * Creates and returns the network interfaces for this transport.
	 *
	 * Broadcasters must be passed to each created NetworkSession so packets can be broadcast across all transports.
	 *
	 * @return NetworkInterface[]
	 * @throws NetworkInterfaceStartException
	 */
	public function createInterfaces(
		PacketBroadcaster $packetBroadcaster,
		EntityEventBroadcaster $entityEventBroadcaster,
		TypeConverter $typeConverter
	) : array;
}
