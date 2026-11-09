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

namespace quark\network\mcpe\command;

use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use quark\command\Command;
use quark\player\Player;

/**
 * Lets players who see the same commands in the same language share one packet during a single resync.
 *
 * @internal
 */
final class CommandMetadataCache{
	/**
	 * @var mixed[][]
	 * @phpstan-var array<string, array{AvailableCommandsPacket, array<string, int>}>
	 */
	private array $entries = [];

	/**
	 * @param Command[] $commands
	 * @phpstan-param array<string, Command> $commands
	 * @param int[] $softEnumVersions
	 * @phpstan-param array<string, int> $softEnumVersions
	 */
	public function get(array $commands, Player $player, array &$softEnumVersions) : AvailableCommandsPacket{
		$signature = CommandMetadataAdapter::createSignature($commands, $player);
		if(!isset($this->entries[$signature])){
			$versions = [];
			$packet = CommandMetadataAdapter::createPacket($commands, $player, $versions);
			$this->entries[$signature] = [$packet, $versions];
		}
		[$packet, $softEnumVersions] = $this->entries[$signature];
		return $packet;
	}
}
