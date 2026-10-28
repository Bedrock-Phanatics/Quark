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

final class AbilitiesLayer{

	public const LAYER_CACHE = 0;
	public const LAYER_BASE = 1;
	public const LAYER_SPECTATOR = 2;
	public const LAYER_COMMANDS = 3;
	public const LAYER_EDITOR = 4;
	public const LAYER_LOADING_SCREEN = 5;

	public const ABILITY_BUILD = 0;
	public const ABILITY_MINE = 1;
	public const ABILITY_DOORS_AND_SWITCHES = 2; //disabling this also disables dropping items (???)
	public const ABILITY_OPEN_CONTAINERS = 3;
	public const ABILITY_ATTACK_PLAYERS = 4;
	public const ABILITY_ATTACK_MOBS = 5;
	public const ABILITY_OPERATOR = 6;
	public const ABILITY_TELEPORT = 7;
	public const ABILITY_INVULNERABLE = 8;
	public const ABILITY_FLYING = 9;
	public const ABILITY_ALLOW_FLIGHT = 10;
	public const ABILITY_INFINITE_RESOURCES = 11; //in vanilla they call this "instabuild", which is a bad name
	public const ABILITY_LIGHTNING = 12; //???
	public const ABILITY_FLY_SPEED = 13;
	public const ABILITY_WALK_SPEED = 14;
	public const ABILITY_MUTED = 15;
	public const ABILITY_WORLD_BUILDER = 16;
	public const ABILITY_NO_CLIP = 17;
	public const ABILITY_PRIVILEGED_BUILDER = 18;
	public const ABILITY_VERTICAL_FLY_SPEED = 19;

	public const NUMBER_OF_ABILITIES = 20;

	/**
	 * @param bool[] $boolAbilities
	 * @phpstan-param array<self::ABILITY_*, bool> $boolAbilities
	 */
	public function __construct(
		private int $layerId,
		private array $boolAbilities,
		private ?float $flySpeed,
		private ?float $verticalFlySpeed,
		private ?float $walkSpeed
	){}

	public function getLayerId() : int{ return $this->layerId; }

	/**
	 * Returns a list of abilities set/overridden by this layer. If the ability value is not set, the index is omitted.
	 * @return bool[]
	 * @phpstan-return array<self::ABILITY_*, bool>
	 */
	public function getBoolAbilities() : array{ return $this->boolAbilities; }

	public function getFlySpeed() : ?float{ return $this->flySpeed; }

	public function getVerticalFlySpeed() : ?float{ return $this->verticalFlySpeed; }

	public function getWalkSpeed() : ?float{ return $this->walkSpeed; }
}
