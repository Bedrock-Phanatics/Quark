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
use quark\command\CommandArgs;
use quark\command\CommandSender;
use quark\console\ConsoleCommandSender;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\utils\TextFormat;

class SayCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"say",
			KnownTranslationFactory::quark_command_say_description()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_SAY);
		$this->registerArgument(new StringArgument("message", greedy: true));
	}

	protected function run(CommandSender $sender, CommandArgs $args) : void{
		$sender->getServer()->broadcastMessage(KnownTranslationFactory::chat_type_announcement(
			$sender instanceof Player ? $sender->getDisplayName() : ($sender instanceof ConsoleCommandSender ? "Server" : $sender->getName()),
			$args->string("message")
		)->prefix(TextFormat::LIGHT_PURPLE));
	}
}
