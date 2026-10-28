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
