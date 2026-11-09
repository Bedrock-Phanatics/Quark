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

use quark\command\argument\UserNameArgument;
use quark\command\Command;
use quark\command\CommandArgs;
use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\utils\TextFormat;

class OpCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"op",
			KnownTranslationFactory::quark_command_op_description()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_OP_GIVE);
		$this->registerArgument(new UserNameArgument("player"));
	}

	protected function run(CommandSender $sender, CommandArgs $args) : void{
		$name = $args->string("player");
		$sender->getServer()->addOp($name);
		$sender->getServer()->getPlayerExact($name)?->sendMessage(KnownTranslationFactory::commands_op_message()->prefix(TextFormat::GRAY));
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_op_success($name));
	}
}
