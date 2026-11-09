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

use quark\command\argument\StringArgument;
use quark\command\Command;
use quark\command\CommandArgs;
use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;

class PardonCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"pardon",
			KnownTranslationFactory::quark_command_unban_player_description(),
			null,
			["unban"]
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_UNBAN_PLAYER);
		$this->registerArgument(new StringArgument("player"));
	}

	protected function run(CommandSender $sender, CommandArgs $args) : void{
		$name = $args->string("player");
		$sender->getServer()->getNameBans()->remove($name);
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_unban_success($name));
	}
}
