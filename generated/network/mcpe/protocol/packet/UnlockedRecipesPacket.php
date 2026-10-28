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

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class UnlockedRecipesPacket extends Packet{
	public const int ID = PacketId::UNLOCKED_RECIPES;

	public const int TYPE_EMPTY = 0;
	public const int TYPE_INITIALLY_UNLOCKED = 1;
	public const int TYPE_NEWLY_UNLOCKED = 2;
	public const int TYPE_REMOVE = 3;
	public const int TYPE_REMOVE_ALL = 4;

	/**
	 * @param list<string> $unlockedRecipesList
	 */
	public function __construct(
		public int $packetType,
		public array $unlockedRecipesList,
	){}

	public function getId() : int{
		return self::ID;
	}
}
