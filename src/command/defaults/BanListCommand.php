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

use quark\command\argument\EnumArgument;
use quark\command\CommandArgs;
use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use quark\permission\BanEntry;
use quark\permission\DefaultPermissionNames;
use function array_map;
use function count;
use function implode;
use function sort;
use const SORT_STRING;

class BanListCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"banlist",
			KnownTranslationFactory::quark_command_banlist_description()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_BAN_LIST);
		$this->registerArgument(new EnumArgument("type", ["ips", "players"], optional: true));
	}

	protected function run(CommandSender $sender, CommandArgs $args) : void{
		$ips = $args->string("type", "players") === "ips";
		$server = $sender->getServer();

		$names = array_map(static fn(BanEntry $entry) : string => $entry->getName(), ($ips ? $server->getIPBans() : $server->getNameBans())->getEntries());
		sort($names, SORT_STRING);

		$count = (string) count($names);
		$sender->sendMessage($ips ? KnownTranslationFactory::commands_banlist_ips($count) : KnownTranslationFactory::commands_banlist_players($count));
		$sender->sendMessage(implode(", ", $names));
	}
}
