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

use quark\command\argument\CommandEnum;
use quark\command\argument\EnumArgument;
use quark\command\argument\PlayerArgument;
use quark\command\Command;
use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\GameMode;
use quark\player\Player;

class GamemodeCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"gamemode",
			KnownTranslationFactory::quark_command_gamemode_description()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_GAMEMODE_SELF,
			DefaultPermissionNames::COMMAND_GAMEMODE_OTHER
		]);

		$modes = [];
		foreach(GameMode::cases() as $case){
			foreach($case->getAliases() as $alias){
				$modes[$alias] = $case;
			}
		}
		$mode = new EnumArgument("mode", CommandEnum::fromMap("GameMode", $modes));

		$this->syntax()
			->permission(DefaultPermissionNames::COMMAND_GAMEMODE_SELF)
			->registerArgument($mode)
			->handler($this->setOwn(...));
		$this->syntax()
			->registerArguments([$mode, new PlayerArgument("target", partial: true, self: true)])
			->handler($this->setOther(...));
	}

	private function setOwn(Player $sender, GameMode $mode) : void{
		$this->change($sender, $sender, $mode);
	}

	private function setOther(CommandSender $sender, GameMode $mode, Player $target) : void{
		if($this->canTarget($sender, $target, DefaultPermissionNames::COMMAND_GAMEMODE_SELF, DefaultPermissionNames::COMMAND_GAMEMODE_OTHER)){
			$this->change($sender, $target, $mode);
		}
	}

	private function change(CommandSender $sender, Player $target, GameMode $mode) : void{
		if($target->getGamemode() === $mode){
			$sender->sendMessage(KnownTranslationFactory::quark_command_gamemode_failure($target->getName()));
			return;
		}

		$target->setGamemode($mode);
		if($mode !== $target->getGamemode()){
			$sender->sendMessage(KnownTranslationFactory::quark_command_gamemode_failure($target->getName()));
		}elseif($target === $sender){
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_gamemode_success_self($mode->getTranslatableName()));
		}else{
			$target->sendMessage(KnownTranslationFactory::gameMode_changed($mode->getTranslatableName()));
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_gamemode_success_other($mode->getTranslatableName(), $target->getName()));
		}
	}
}
