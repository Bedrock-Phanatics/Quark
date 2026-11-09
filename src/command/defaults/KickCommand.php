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

use quark\command\argument\PlayerArgument;
use quark\command\argument\StringArgument;
use quark\command\Command;
use quark\command\CommandArgs;
use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;

class KickCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"kick",
			KnownTranslationFactory::quark_command_kick_description()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_KICK);
		$this->registerArguments([
			new PlayerArgument("player", partial: true),
			new StringArgument("reason", optional: true, greedy: true)
		]);
	}

	protected function run(CommandSender $sender, CommandArgs $args) : void{
		$player = $args->player("player");
		$reason = $args->string("reason", "");

		$player->kick($reason !== "" ? KnownTranslationFactory::quark_disconnect_kick($reason) : KnownTranslationFactory::quark_disconnect_kick_noReason());
		Command::broadcastCommandMessage($sender, $reason !== "" ?
			KnownTranslationFactory::commands_kick_success_reason($player->getName(), $reason) :
			KnownTranslationFactory::commands_kick_success($player->getName()));
	}
}
