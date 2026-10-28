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
final class CompletedUsingItemPacket extends Packet{
	public const int ID = PacketId::COMPLETED_USING_ITEM;

	public const int ACTION_UNKNOWN = -1;
	public const int ACTION_EQUIP_ARMOR = 0;
	public const int ACTION_EAT = 1;
	public const int ACTION_ATTACK = 2;
	public const int ACTION_CONSUME = 3;
	public const int ACTION_THROW = 4;
	public const int ACTION_SHOOT = 5;
	public const int ACTION_PLACE = 6;
	public const int ACTION_FILL_BOTTLE = 7;
	public const int ACTION_FILL_BUCKET = 8;
	public const int ACTION_POUR_BUCKET = 9;
	public const int ACTION_USE_TOOL = 10;
	public const int ACTION_INTERACT = 11;
	public const int ACTION_RETRIEVED = 12;
	public const int ACTION_DYED = 13;
	public const int ACTION_TRADED = 14;
	public const int ACTION_BRUSHING_COMPLETED = 15;

	public function __construct(
		public int $itemId,
		public int $itemUseMethod,
	){}

	public function getId() : int{
		return self::ID;
	}
}
