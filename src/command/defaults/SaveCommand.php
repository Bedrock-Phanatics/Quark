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
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use function microtime;
use function round;

class SaveCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"save-all",
			KnownTranslationFactory::quark_command_save_description()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_SAVE_PERFORM);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::quark_save_start());
		$start = microtime(true);

		foreach($sender->getServer()->getOnlinePlayers() as $player){
			$player->save();
		}

		foreach($sender->getServer()->getWorldManager()->getWorlds() as $world){
			$world->save(true);
		}

		Command::broadcastCommandMessage($sender, KnownTranslationFactory::quark_save_success((string) round(microtime(true) - $start, 3)));

		return true;
	}
}
