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

use pocketmine\utils\Limits;
use quark\command\argument\CommandEnum;
use quark\command\argument\EnumArgument;
use quark\command\argument\IntegerArgument;
use quark\command\Command;
use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\utils\AssumptionFailedError;
use quark\world\World;
use function intdiv;

class TimeCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"time",
			KnownTranslationFactory::quark_command_time_description()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_TIME_ADD,
			DefaultPermissionNames::COMMAND_TIME_SET,
			DefaultPermissionNames::COMMAND_TIME_START,
			DefaultPermissionNames::COMMAND_TIME_STOP,
			DefaultPermissionNames::COMMAND_TIME_QUERY
		]);

		$this->syntax("start")->permission(DefaultPermissionNames::COMMAND_TIME_START)->handler($this->start(...));
		$this->syntax("stop")->permission(DefaultPermissionNames::COMMAND_TIME_STOP)->handler($this->stop(...));
		$this->syntax("query")
			->permission(DefaultPermissionNames::COMMAND_TIME_QUERY)
			->registerArgument(new EnumArgument("type", ["daytime", "gametime", "day"], optional: true))
			->handler($this->query(...));
		$this->syntax("set")
			->permission(DefaultPermissionNames::COMMAND_TIME_SET)
			->registerArgument(new IntegerArgument("time", 0, Limits::INT32_MAX))
			->handler($this->set(...));
		$this->syntax("set")
			->permission(DefaultPermissionNames::COMMAND_TIME_SET)
			->registerArgument(new EnumArgument("time", CommandEnum::fromMap("TimeSpec", [
				"day" => World::TIME_DAY,
				"noon" => World::TIME_NOON,
				"sunset" => World::TIME_SUNSET,
				"night" => World::TIME_NIGHT,
				"midnight" => World::TIME_MIDNIGHT,
				"sunrise" => World::TIME_SUNRISE
			])))
			->handler($this->set(...));
		$this->syntax("add")
			->permission(DefaultPermissionNames::COMMAND_TIME_ADD)
			->registerArgument(new IntegerArgument("time", 0, Limits::INT32_MAX))
			->handler($this->add(...));
	}

	private function start(CommandSender $sender) : void{
		foreach($sender->getServer()->getWorldManager()->getWorlds() as $world){
			$world->startTime();
		}
		Command::broadcastCommandMessage($sender, "Restarted the time");
	}

	private function stop(CommandSender $sender) : void{
		foreach($sender->getServer()->getWorldManager()->getWorlds() as $world){
			$world->stopTime();
		}
		Command::broadcastCommandMessage($sender, "Stopped the time");
	}

	private function query(CommandSender $sender, string $type = "gametime") : void{
		$world = $sender instanceof Player ? $sender->getWorld() : $sender->getServer()->getWorldManager()->getDefaultWorld() ?? throw new AssumptionFailedError("No default world");
		$time = match($type){
			"daytime" => $world->getTimeOfDay(),
			"day" => intdiv($world->getTime(), World::TIME_FULL),
			default => $world->getTime()
		};
		$sender->sendMessage($sender->getLanguage()->translate(KnownTranslationFactory::commands_time_query((string) $time)));
	}

	private function set(CommandSender $sender, int $time) : void{
		foreach($sender->getServer()->getWorldManager()->getWorlds() as $world){
			$world->setTime($time);
		}
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_time_set((string) $time));
	}

	private function add(CommandSender $sender, int $time) : void{
		foreach($sender->getServer()->getWorldManager()->getWorlds() as $world){
			$world->setTime($world->getTime() + $time);
		}
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_time_added((string) $time));
	}
}
