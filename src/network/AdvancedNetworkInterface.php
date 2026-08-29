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

/**
 * Network-related classes
 */
namespace quark\network;

/**
 * Advanced network interfaces have some additional capabilities, such as being able to ban addresses and process raw
 * packets.
 */
interface AdvancedNetworkInterface extends NetworkInterface{

	/**
	 * Prevents packets received from the IP address getting processed for the given timeout.
	 *
	 * @param int $timeout Seconds
	 */
	public function blockAddress(string $address, int $timeout = 300) : void;

	/**
	 * Unblocks a previously-blocked address.
	 */
	public function unblockAddress(string $address) : void;

	public function setNetwork(Network $network) : void;

	/**
	 * Sends a raw payload to the network interface, bypassing any sessions.
	 */
	public function sendRawPacket(string $address, int $port, string $payload) : void;

	/**
	 * Adds a regex filter for raw packets to this network interface. This filter should be used to check validity of
	 * raw packets before relaying them to the main thread.
	 */
	public function addRawPacketFilter(string $regex) : void;
}
