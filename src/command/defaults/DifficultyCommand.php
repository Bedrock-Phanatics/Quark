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

use quark\command\argument\CommandEnum;
use quark\command\argument\EnumArgument;
use quark\command\Command;
use quark\command\CommandArgs;
use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\ServerProperties;
use quark\world\World;

class DifficultyCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"difficulty",
			KnownTranslationFactory::quark_command_difficulty_description()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_DIFFICULTY);
		$this->registerArgument(new EnumArgument("difficulty", CommandEnum::fromValues("Difficulty", ["peaceful", "p", "0", "easy", "e", "1", "normal", "n", "2", "hard", "h", "3"])));
	}

	protected function run(CommandSender $sender, CommandArgs $args) : void{
		$server = $sender->getServer();
		$difficulty = $server->isHardcore() ? World::DIFFICULTY_HARD : World::getDifficultyFromString($args->string("difficulty"));

		$server->getConfigGroup()->setConfigInt(ServerProperties::DIFFICULTY, $difficulty);
		//TODO: add per-world support
		foreach($server->getWorldManager()->getWorlds() as $world){
			$world->setDifficulty($difficulty);
		}
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_difficulty_success((string) $difficulty));
	}
}
