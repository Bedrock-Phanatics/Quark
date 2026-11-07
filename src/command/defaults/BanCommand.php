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
use function array_shift;
use function count;
use function implode;

class BanCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"ban",
			KnownTranslationFactory::quark_command_ban_player_description(),
			KnownTranslationFactory::commands_ban_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_BAN_PLAYER);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) === 0){
			throw new InvalidCommandSyntaxException();
		}

		$name = array_shift($args);
		$reason = implode(" ", $args);

		$sender->getServer()->getNameBans()->addBan($name, $reason, null, $sender->getName());

		if(($player = $sender->getServer()->getPlayerExact($name)) instanceof Player){
			$player->kick($reason !== "" ? KnownTranslationFactory::quark_disconnect_ban($reason) : KnownTranslationFactory::quark_disconnect_ban_noReason());
		}

		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_ban_success($player !== null ? $player->getName() : $name));

		return true;
	}
}
