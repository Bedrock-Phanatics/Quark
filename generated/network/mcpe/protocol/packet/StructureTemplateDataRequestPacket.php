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
use quark\network\mcpe\protocol\types\StructureSettings;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class StructureTemplateDataRequestPacket extends Packet{
	public const int ID = PacketId::STRUCTURE_TEMPLATE_DATA_REQUEST;

	public function __construct(
		public string $structureName,
		public Vector3 $structurePosition,
		public StructureSettings $structureSettings,
		public int $requestedOperation,
	){}

	public function getId() : int{
		return self::ID;
	}
}
