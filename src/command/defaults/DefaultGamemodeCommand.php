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

use quark\command\CommandSender;
use quark\command\utils\InvalidCommandSyntaxException;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\GameMode;
use quark\ServerProperties;
use function count;

class DefaultGamemodeCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"defaultgamemode",
			KnownTranslationFactory::quark_command_defaultgamemode_description(),
			KnownTranslationFactory::commands_defaultgamemode_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_DEFAULTGAMEMODE);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) === 0){
			throw new InvalidCommandSyntaxException();
		}

		$gameMode = GameMode::fromString($args[0]);
		if($gameMode === null){
			$sender->sendMessage(KnownTranslationFactory::quark_command_gamemode_unknown($args[0]));
			return true;
		}

		//TODO: this probably shouldn't use the enum name directly
		$sender->getServer()->getConfigGroup()->setConfigString(ServerProperties::GAME_MODE, $gameMode->name);
		$sender->sendMessage(KnownTranslationFactory::commands_defaultgamemode_success($gameMode->getTranslatableName()));
		return true;
	}
}
