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
use quark\event\entity\EntityDamageEvent;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use function count;

class KillCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"kill",
			KnownTranslationFactory::quark_command_kill_description(),
			KnownTranslationFactory::quark_command_kill_usage(),
			["suicide"]
		);
		$this->setPermissions([DefaultPermissionNames::COMMAND_KILL_SELF, DefaultPermissionNames::COMMAND_KILL_OTHER]);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) >= 2){
			throw new InvalidCommandSyntaxException();
		}

		$player = $this->fetchPermittedPlayerTarget($sender, $args[0] ?? null, DefaultPermissionNames::COMMAND_KILL_SELF, DefaultPermissionNames::COMMAND_KILL_OTHER);
		if($player === null){
			return true;
		}

		$player->attack(new EntityDamageEvent($player, EntityDamageEvent::CAUSE_SUICIDE, $player->getHealth()));
		if($player === $sender){
			$sender->sendMessage(KnownTranslationFactory::commands_kill_successful($sender->getName()));
		}else{
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_kill_successful($player->getName()));
		}

		return true;
	}
}
