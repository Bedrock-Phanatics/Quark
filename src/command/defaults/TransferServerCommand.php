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

use quark\command\argument\IntegerArgument;
use quark\command\argument\StringArgument;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;

class TransferServerCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"transferserver",
			KnownTranslationFactory::quark_command_transferserver_description()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_TRANSFERSERVER);
		$this->syntax()
			->registerArguments([
				new StringArgument("address"),
				new IntegerArgument("port", 0, 65535, optional: true)
			])
			->handler($this->transfer(...));
	}

	private function transfer(Player $sender, string $address, ?int $port = null) : void{
		$sender->transfer($address, $port ?? 19132);
	}
}
