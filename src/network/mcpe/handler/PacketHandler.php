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

namespace quark\network\mcpe\handler;

use pocketmine\network\mcpe\protocol\PacketHandlerDefaultImplTrait;
use pocketmine\network\mcpe\protocol\PacketHandlerInterface;

/**
 * Handlers are attached to sessions to handle packets received from their associated clients. A handler
 * is mutable and may be removed/replaced at any time.
 *
 * This class is an automatically generated stub. Do not edit it manually.
 */
abstract class PacketHandler implements PacketHandlerInterface{
	use PacketHandlerDefaultImplTrait;

	public function setUp() : void{

	}
}
