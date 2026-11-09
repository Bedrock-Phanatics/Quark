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
use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\Server;
use quark\ServerProperties;
use function count;
use function implode;
use function sort;
use const SORT_STRING;

class WhitelistCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"whitelist",
			KnownTranslationFactory::quark_command_whitelist_description()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_WHITELIST_RELOAD,
			DefaultPermissionNames::COMMAND_WHITELIST_ENABLE,
			DefaultPermissionNames::COMMAND_WHITELIST_DISABLE,
			DefaultPermissionNames::COMMAND_WHITELIST_LIST,
			DefaultPermissionNames::COMMAND_WHITELIST_ADD,
			DefaultPermissionNames::COMMAND_WHITELIST_REMOVE
		]);

		$this->syntax("reload")->permission(DefaultPermissionNames::COMMAND_WHITELIST_RELOAD)->handler($this->reload(...));
		$this->syntax("on")->permission(DefaultPermissionNames::COMMAND_WHITELIST_ENABLE)->handler($this->enable(...));
		$this->syntax("off")->permission(DefaultPermissionNames::COMMAND_WHITELIST_DISABLE)->handler($this->disable(...));
		$this->syntax("list")->permission(DefaultPermissionNames::COMMAND_WHITELIST_LIST)->handler($this->showList(...));
		$this->syntax("add")
			->permission(DefaultPermissionNames::COMMAND_WHITELIST_ADD)
			->registerArgument(new UserNameArgument("player"))
			->handler($this->add(...));
		$this->syntax("remove")
			->permission(DefaultPermissionNames::COMMAND_WHITELIST_REMOVE)
			->registerArgument(new UserNameArgument("player"))
			->handler($this->remove(...));
	}

	private function reload(CommandSender $sender) : void{
		$server = $sender->getServer();
		$server->getWhitelisted()->reload();
		if($server->hasWhitelist()){
			$this->kickNonWhitelistedPlayers($server);
		}
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_whitelist_reloaded());
	}

	private function enable(CommandSender $sender) : void{
		$server = $sender->getServer();
		$server->getConfigGroup()->setConfigBool(ServerProperties::WHITELIST, true);
		$this->kickNonWhitelistedPlayers($server);
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_whitelist_enabled());
	}

	private function disable(CommandSender $sender) : void{
		$sender->getServer()->getConfigGroup()->setConfigBool(ServerProperties::WHITELIST, false);
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_whitelist_disabled());
	}

	private function showList(CommandSender $sender) : void{
		$entries = $sender->getServer()->getWhitelisted()->getAll(true);
		sort($entries, SORT_STRING);
		$count = (string) count($entries);
		$sender->sendMessage(KnownTranslationFactory::commands_whitelist_list($count, $count));
		$sender->sendMessage(implode(", ", $entries));
	}

	private function add(CommandSender $sender, string $player) : void{
		$sender->getServer()->addWhitelist($player);
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_whitelist_add_success($player));
	}

	private function remove(CommandSender $sender, string $player) : void{
		$server = $sender->getServer();
		$server->removeWhitelist($player);
		if(!$server->isWhitelisted($player)){
			$server->getPlayerExact($player)?->kick(KnownTranslationFactory::quark_disconnect_kick(KnownTranslationFactory::quark_disconnect_whitelisted()));
		}
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_whitelist_remove_success($player));
	}

	private function kickNonWhitelistedPlayers(Server $server) : void{
		$message = KnownTranslationFactory::quark_disconnect_kick(KnownTranslationFactory::quark_disconnect_whitelisted());
		foreach($server->getOnlinePlayers() as $player){
			if(!$server->isWhitelisted($player->getName())){
				$player->kick($message);
			}
		}
	}
}
