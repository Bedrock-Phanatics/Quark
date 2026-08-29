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
use function count;
use function inet_pton;

class PardonIpCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"pardon-ip",
			KnownTranslationFactory::quark_command_unban_ip_description(),
			KnownTranslationFactory::commands_unbanip_usage(),
			["unban-ip"]
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_UNBAN_IP);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) !== 1){
			throw new InvalidCommandSyntaxException();
		}

		if(inet_pton($args[0]) !== false){
			$sender->getServer()->getIPBans()->remove($args[0]);
			$sender->getServer()->getNetwork()->unblockAddress($args[0]);
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_unbanip_success($args[0]));
		}else{
			$sender->sendMessage(KnownTranslationFactory::commands_unbanip_invalid());
		}

		return true;
	}
}
