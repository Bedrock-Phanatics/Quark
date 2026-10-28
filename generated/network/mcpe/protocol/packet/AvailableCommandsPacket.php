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
use quark\network\mcpe\protocol\types\ChainedSubcommand;
use quark\network\mcpe\protocol\types\Command;
use quark\network\mcpe\protocol\types\CommandEnum;
use quark\network\mcpe\protocol\types\CommandEnumConstraint;
use quark\network\mcpe\protocol\types\DynamicEnum;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class AvailableCommandsPacket extends Packet{
	public const int ID = PacketId::AVAILABLE_COMMANDS;

	public const int ARG_FLAG_VALID = 1048576;
	public const int ARG_TYPE_INT = 1;
	public const int ARG_TYPE_FLOAT = 3;
	public const int ARG_TYPE_VALUE = 4;
	public const int ARG_TYPE_WILDCARD_INT = 5;
	public const int ARG_TYPE_OPERATOR = 6;
	public const int ARG_TYPE_COMPARE_OPERATOR = 7;
	public const int ARG_TYPE_TARGET = 8;
	public const int ARG_TYPE_WILDCARD_TARGET = 10;
	public const int ARG_TYPE_FILEPATH = 17;
	public const int ARG_TYPE_FULL_INTEGER_RANGE = 23;
	public const int ARG_TYPE_EQUIPMENT_SLOT = 47;
	public const int ARG_TYPE_STRING = 56;
	public const int ARG_TYPE_INT_POSITION = 64;
	public const int ARG_TYPE_POSITION = 65;
	public const int ARG_TYPE_MESSAGE = 68;
	public const int ARG_TYPE_RAWTEXT = 70;
	public const int ARG_TYPE_JSON = 74;
	public const int ARG_TYPE_BLOCK_STATES = 84;
	public const int ARG_TYPE_COMMAND = 88;
	public const int ARG_FLAG_ENUM = 2097152;
	public const int ARG_FLAG_POSTFIX = 16777216;
	public const int ARG_FLAG_SOFT_ENUM = 67108864;

	/**
	 * @param list<string> $enumValues
	 * @param list<string> $chainedSubcommandValues
	 * @param list<string> $postFixes
	 * @param list<CommandEnum> $enumData
	 * @param list<ChainedSubcommand> $chainedSubcommandData
	 * @param list<Command> $commands
	 * @param list<DynamicEnum> $softEnums
	 * @param list<CommandEnumConstraint> $constraints
	 */
	public function __construct(
		public array $enumValues,
		public array $chainedSubcommandValues,
		public array $postFixes,
		public array $enumData,
		public array $chainedSubcommandData,
		public array $commands,
		public array $softEnums,
		public array $constraints,
	){}

	public function getId() : int{
		return self::ID;
	}
}
