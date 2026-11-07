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
use quark\player\Player;
use quark\utils\TextFormat;
use function count;
use function implode;

class MeCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"me",
			KnownTranslationFactory::quark_command_me_description(),
			KnownTranslationFactory::commands_me_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_ME);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) === 0){
			throw new InvalidCommandSyntaxException();
		}

		$sender->getServer()->broadcastMessage(KnownTranslationFactory::chat_type_emote($sender instanceof Player ? $sender->getDisplayName() : $sender->getName(), TextFormat::RESET . implode(" ", $args)));

		return true;
	}
}
