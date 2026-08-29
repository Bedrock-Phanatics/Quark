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
use quark\ServerProperties;
use quark\world\World;
use function count;

class DifficultyCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"difficulty",
			KnownTranslationFactory::quark_command_difficulty_description(),
			KnownTranslationFactory::commands_difficulty_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_DIFFICULTY);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) !== 1){
			throw new InvalidCommandSyntaxException();
		}

		$difficulty = World::getDifficultyFromString($args[0]);

		if($sender->getServer()->isHardcore()){
			$difficulty = World::DIFFICULTY_HARD;
		}

		if($difficulty !== -1){
			$sender->getServer()->getConfigGroup()->setConfigInt(ServerProperties::DIFFICULTY, $difficulty);

			//TODO: add per-world support
			foreach($sender->getServer()->getWorldManager()->getWorlds() as $world){
				$world->setDifficulty($difficulty);
			}

			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_difficulty_success((string) $difficulty));
		}else{
			throw new InvalidCommandSyntaxException();
		}

		return true;
	}
}
