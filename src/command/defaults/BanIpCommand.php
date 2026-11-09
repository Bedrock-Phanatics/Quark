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

use quark\command\argument\IpAddressArgument;
use quark\command\argument\StringArgument;
use quark\command\Command;
use quark\command\CommandArgs;
use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;

class BanIpCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"ban-ip",
			KnownTranslationFactory::quark_command_ban_ip_description()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_BAN_IP);
		$this->registerArguments([
			new IpAddressArgument("target", orPlayer: true),
			new StringArgument("reason", optional: true, greedy: true)
		]);
	}

	protected function run(CommandSender $sender, CommandArgs $args) : void{
		$target = $args->get("target");
		$reason = $args->string("reason", "");

		$ip = $target instanceof Player ? $target->getNetworkSession()->getIp() : $args->string("target");
		$this->processIPBan($ip, $sender, $reason);
		Command::broadcastCommandMessage($sender, $target instanceof Player ?
			KnownTranslationFactory::commands_banip_success_players($ip, $target->getName()) :
			KnownTranslationFactory::commands_banip_success($ip));
	}

	private function processIPBan(string $ip, CommandSender $sender, string $reason) : void{
		$sender->getServer()->getIPBans()->addBan($ip, $reason, null, $sender->getName());

		foreach($sender->getServer()->getOnlinePlayers() as $player){
			if($player->getNetworkSession()->getIp() === $ip){
				$player->kick(KnownTranslationFactory::quark_disconnect_ban($reason !== "" ? $reason : KnownTranslationFactory::quark_disconnect_ban_ip()));
			}
		}

		$sender->getServer()->getNetwork()->blockAddress($ip, -1);
	}
}
