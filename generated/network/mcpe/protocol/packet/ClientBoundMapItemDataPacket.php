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
use pocketmine\math\Vector3;
use quark\network\mcpe\protocol\Packet;
use quark\network\mcpe\protocol\types\MapDecoration;
use quark\network\mcpe\protocol\types\MapTrackedObject;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class ClientBoundMapItemDataPacket extends Packet{
	public const int ID = PacketId::CLIENT_BOUND_MAP_ITEM_DATA;

	/**
	 * @param list<int>|null $creationMapIDs
	 * @param list<MapTrackedObject>|null $trackedActorIDs
	 * @param list<MapDecoration>|null $decorations
	 * @param list<int>|null $pixels
	 */
	public function __construct(
		public int $mapId,
		public int $dimension,
		public bool $isLocked,
		public Vector3 $mapOrigin,
		public ?array $creationMapIDs = null,
		public ?int $scale = null,
		public ?array $trackedActorIDs = null,
		public ?array $decorations = null,
		public ?int $width = null,
		public ?int $height = null,
		public ?int $startX = null,
		public ?int $startY = null,
		public ?array $pixels = null,
	){}

	public function getId() : int{
		return self::ID;
	}
}
