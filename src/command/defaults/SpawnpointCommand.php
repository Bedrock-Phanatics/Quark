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

use quark\command\Command;
use quark\command\CommandSender;
use quark\command\utils\InvalidCommandSyntaxException;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\world\Position;
use quark\world\World;
use function count;
use function round;

class SpawnpointCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"spawnpoint",
			KnownTranslationFactory::quark_command_spawnpoint_description(),
			KnownTranslationFactory::commands_spawnpoint_usage()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_SPAWNPOINT_SELF,
			DefaultPermissionNames::COMMAND_SPAWNPOINT_OTHER
		]);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		$target = $this->fetchPermittedPlayerTarget($sender, $args[0] ?? null, DefaultPermissionNames::COMMAND_SPAWNPOINT_SELF, DefaultPermissionNames::COMMAND_SPAWNPOINT_OTHER);
		if($target === null){
			return true;
		}

		if(count($args) === 4){
			$world = $target->getWorld();
			$pos = $sender instanceof Player ? $sender->getPosition() : $world->getSpawnLocation();
			$x = $this->getRelativeDouble($pos->x, $sender, $args[1]);
			$y = $this->getRelativeDouble($pos->y, $sender, $args[2], World::Y_MIN, World::Y_MAX);
			$z = $this->getRelativeDouble($pos->z, $sender, $args[3]);
			$target->setSpawn(new Position($x, $y, $z, $world));

			Command::broadcastCommandMessage($sender, KnownTranslationFactory::quark_command_spawnpoint_success($target->getName(), (string) round($x, 2), (string) round($y, 2), (string) round($z, 2)));

			return true;
		}elseif(count($args) <= 1 && $sender instanceof Player){
			$cpos = $sender->getPosition();
			$pos = Position::fromObject($cpos->floor(), $cpos->getWorld());
			$target->setSpawn($pos);

			Command::broadcastCommandMessage($sender, KnownTranslationFactory::quark_command_spawnpoint_success($target->getName(), (string) round($pos->x, 2), (string) round($pos->y, 2), (string) round($pos->z, 2)));
			return true;
		}

		throw new InvalidCommandSyntaxException();
	}
}
