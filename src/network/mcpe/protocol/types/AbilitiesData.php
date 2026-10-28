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

namespace quark\network\mcpe\protocol\types;

use quark\network\mcpe\protocol\types\command\CommandPermissions;

final class AbilitiesData{
	/**
	 * @param AbilitiesLayer[] $abilityLayers
	 * @phpstan-param array<int, AbilitiesLayer> $abilityLayers
	 */
	public function __construct(
		private CommandPermissions $commandPermission,
		private PlayerPermissions $playerPermission,
		private int $targetActorUniqueId, //This is a little-endian long, NOT a var-long. (WTF Mojang)
		private array $abilityLayers
	){}

	public function getCommandPermission() : CommandPermissions{ return $this->commandPermission; }

	public function getPlayerPermission() : PlayerPermissions{ return $this->playerPermission; }

	public function getTargetActorUniqueId() : int{ return $this->targetActorUniqueId; }

	/**
	 * @return AbilitiesLayer[]
	 * @phpstan-return array<int, AbilitiesLayer>
	 */
	public function getAbilityLayers() : array{ return $this->abilityLayers; }
}
