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

class TransferServerCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"transferserver",
			KnownTranslationFactory::quark_command_transferserver_description(),
			KnownTranslationFactory::quark_command_transferserver_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_TRANSFERSERVER);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 1){
			throw new InvalidCommandSyntaxException();
		}elseif(!($sender instanceof Player)){
			$sender->sendMessage(KnownTranslationFactory::quark_command_error_playerUserOnly()->prefix(TextFormat::RED));

			return false;
		}

		$sender->transfer($args[0], (int) ($args[1] ?? 19132));

		return true;
	}
}
