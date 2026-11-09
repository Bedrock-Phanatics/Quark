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
use quark\command\Command;
use quark\command\CommandSender;
use quark\event\entity\EntityDamageEvent;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;

class KillCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"kill",
			KnownTranslationFactory::quark_command_kill_description(),
			null,
			["suicide"]
		);
		$this->setPermissions([DefaultPermissionNames::COMMAND_KILL_SELF, DefaultPermissionNames::COMMAND_KILL_OTHER]);

		$this->syntax()
			->permission(DefaultPermissionNames::COMMAND_KILL_SELF)
			->handler($this->killSelf(...));
		$this->syntax()
			->registerArgument(new PlayerArgument("target", partial: true, self: true))
			->handler($this->killTarget(...));
	}

	private function killSelf(Player $sender) : void{
		$this->kill($sender, $sender);
	}

	private function killTarget(CommandSender $sender, Player $target) : void{
		if($this->canTarget($sender, $target, DefaultPermissionNames::COMMAND_KILL_SELF, DefaultPermissionNames::COMMAND_KILL_OTHER)){
			$this->kill($sender, $target);
		}
	}

	private function kill(CommandSender $sender, Player $player) : void{
		$player->attack(new EntityDamageEvent($player, EntityDamageEvent::CAUSE_SUICIDE, $player->getHealth()));
		if($player === $sender){
			$sender->sendMessage(KnownTranslationFactory::commands_kill_successful($sender->getName()));
		}else{
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_kill_successful($player->getName()));
		}
	}
}
