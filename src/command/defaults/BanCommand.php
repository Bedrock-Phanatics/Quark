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

class BanCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"ban",
			KnownTranslationFactory::quark_command_ban_player_description()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_BAN_PLAYER);
		$this->registerArguments([
			new StringArgument("player"),
			new StringArgument("reason", optional: true, greedy: true)
		]);
	}

	protected function run(CommandSender $sender, CommandArgs $args) : void{
		$name = $args->string("player");
		$reason = $args->string("reason", "");

		$server = $sender->getServer();
		$server->getNameBans()->addBan($name, $reason, null, $sender->getName());

		$player = $server->getPlayerExact($name);
		$player?->kick($reason !== "" ? KnownTranslationFactory::quark_disconnect_ban($reason) : KnownTranslationFactory::quark_disconnect_ban_noReason());

		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_ban_success($player !== null ? $player->getName() : $name));
	}
}
