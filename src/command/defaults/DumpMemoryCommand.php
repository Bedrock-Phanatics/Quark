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
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use Symfony\Component\Filesystem\Path;
use function date;

class DumpMemoryCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"dumpmemory",
			KnownTranslationFactory::quark_command_dumpmemory_description(),
			"/dumpmemory [path]"
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_DUMPMEMORY);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		$sender->getServer()->getMemoryManager()->dumpServerMemory($args[0] ?? (Path::join($sender->getServer()->getDataPath(), "memory_dumps", date("D_M_j-H.i.s-T_Y"))), 48, 80);
		return true;
	}
}
