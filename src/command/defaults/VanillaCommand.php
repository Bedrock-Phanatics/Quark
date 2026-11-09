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

namespace quark\command\defaults;

use pocketmine\math\Vector3;
use quark\command\argument\RelativePosition;
use quark\command\Command;
use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use quark\player\Player;
use quark\utils\TextFormat;
use quark\world\World;
use function round;

abstract class VanillaCommand extends Command{
	public const MAX_COORD = 30000000;
	public const MIN_COORD = -30000000;

	/**
	 * Checks the permission for acting on $target, replying to the sender when it is missing.
	 */
	protected function canTarget(CommandSender $sender, Player $target, string $selfPermission, string $otherPermission) : bool{
		return $this->testPermission($sender, $target === $sender ? $selfPermission : $otherPermission);
	}

	/**
	 * Resolves a position against a base and replies with the violated limit when it falls outside the world.
	 */
	protected function resolvePosition(CommandSender $sender, RelativePosition $position, Vector3 $base, int $minY = World::Y_MIN, int $maxY = World::Y_MAX) : ?Vector3{
		$resolved = $position->resolve($base);
		foreach([[$resolved->x, self::MIN_COORD, self::MAX_COORD], [$resolved->y, $minY, $maxY], [$resolved->z, self::MIN_COORD, self::MAX_COORD]] as [$value, $min, $max]){
			if($value < $min){
				$sender->sendMessage(KnownTranslationFactory::commands_generic_num_tooSmall((string) round($value, 2), (string) $min)->prefix(TextFormat::RED));
				return null;
			}
			if($value > $max){
				$sender->sendMessage(KnownTranslationFactory::commands_generic_num_tooBig((string) round($value, 2), (string) $max)->prefix(TextFormat::RED));
				return null;
			}
		}
		return $resolved;
	}
}
