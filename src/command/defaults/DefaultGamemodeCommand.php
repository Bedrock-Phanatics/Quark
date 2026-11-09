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
use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\GameMode;
use quark\ServerProperties;

class DefaultGamemodeCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"defaultgamemode",
			KnownTranslationFactory::quark_command_defaultgamemode_description()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_DEFAULTGAMEMODE);

		$modes = [];
		foreach(GameMode::cases() as $mode){
			foreach($mode->getAliases() as $alias){
				$modes[$alias] = $mode;
			}
		}
		$this->syntax()
			->registerArgument(new EnumArgument("gamemode", CommandEnum::fromMap("GameMode", $modes)))
			->handler($this->setDefault(...));
	}

	private function setDefault(CommandSender $sender, GameMode $gameMode) : void{
		//TODO: this probably shouldn't use the enum name directly
		$sender->getServer()->getConfigGroup()->setConfigString(ServerProperties::GAME_MODE, $gameMode->name);
		$sender->sendMessage(KnownTranslationFactory::commands_defaultgamemode_success($gameMode->getTranslatableName()));
	}
}
