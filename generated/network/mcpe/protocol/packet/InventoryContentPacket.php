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

namespace quark\network\mcpe\protocol\packet;

use Bedwire\PacketId;
use quark\network\mcpe\protocol\Packet;
use quark\network\mcpe\protocol\types\FullContainerName;
use quark\network\mcpe\protocol\types\NetworkItemStackDescriptor;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class InventoryContentPacket extends Packet{
	public const int ID = PacketId::INVENTORY_CONTENT;

	/**
	 * @param list<NetworkItemStackDescriptor> $slots
	 */
	public function __construct(
		public int $containerId,
		public array $slots,
		public FullContainerName $fullContainerName,
		public NetworkItemStackDescriptor $storageItem,
	){}

	public function getId() : int{
		return self::ID;
	}
}
