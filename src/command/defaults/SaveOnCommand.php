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

class SaveOnCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"save-on",
			KnownTranslationFactory::quark_command_saveon_description()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_SAVE_ENABLE);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		$sender->getServer()->getWorldManager()->setAutoSave(true);

		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_save_enabled());

		return true;
	}
}
