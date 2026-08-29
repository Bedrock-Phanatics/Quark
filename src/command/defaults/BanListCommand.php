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
use quark\command\utils\InvalidCommandSyntaxException;
use quark\lang\KnownTranslationFactory;
use quark\permission\BanEntry;
use quark\permission\DefaultPermissionNames;
use function array_map;
use function count;
use function implode;
use function sort;
use function strtolower;
use const SORT_STRING;

class BanListCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"banlist",
			KnownTranslationFactory::quark_command_banlist_description(),
			KnownTranslationFactory::commands_banlist_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_BAN_LIST);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(isset($args[0])){
			$args[0] = strtolower($args[0]);
			if($args[0] === "ips"){
				$list = $sender->getServer()->getIPBans();
			}elseif($args[0] === "players"){
				$list = $sender->getServer()->getNameBans();
			}else{
				throw new InvalidCommandSyntaxException();
			}
		}else{
			$list = $sender->getServer()->getNameBans();
			$args[0] = "players";
		}

		$list = array_map(function(BanEntry $entry) : string{
			return $entry->getName();
		}, $list->getEntries());
		sort($list, SORT_STRING);
		$message = implode(", ", $list);

		if($args[0] === "ips"){
			$sender->sendMessage(KnownTranslationFactory::commands_banlist_ips((string) count($list)));
		}else{
			$sender->sendMessage(KnownTranslationFactory::commands_banlist_players((string) count($list)));
		}

		$sender->sendMessage($message);

		return true;
	}
}
