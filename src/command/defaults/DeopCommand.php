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
use quark\utils\TextFormat;
use function array_shift;
use function count;

class DeopCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"deop",
			KnownTranslationFactory::quark_command_deop_description(),
			KnownTranslationFactory::commands_deop_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_OP_TAKE);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) === 0){
			throw new InvalidCommandSyntaxException();
		}

		$name = array_shift($args);
		if(!Player::isValidUserName($name)){
			throw new InvalidCommandSyntaxException();
		}

		$sender->getServer()->removeOp($name);
		if(($player = $sender->getServer()->getPlayerExact($name)) !== null){
			$player->sendMessage(KnownTranslationFactory::commands_deop_message()->prefix(TextFormat::GRAY));
		}
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_deop_success($name));

		return true;
	}
}
