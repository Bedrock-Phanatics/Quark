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
use quark\command\argument\IntegerArgument;
use quark\command\Command;
use quark\command\CommandSender;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\utils\AssumptionFailedError;
use quark\utils\TextFormat;
use quark\world\Weather;
use function strtolower;

final class WeatherCommand extends VanillaCommand{
	public const MAX_DURATION = 1000000;

	public function __construct(){
		parent::__construct("weather", "Changes the weather");
		$this->setPermission(DefaultPermissionNames::COMMAND_WEATHER);

		$this->syntax("query")->handler($this->query(...));
		$this->syntax()
			->registerArguments([
				new EnumArgument("weather", Weather::class),
				new IntegerArgument("duration", 1, self::MAX_DURATION, optional: true)
			])
			->handler($this->change(...));
	}

	private function query(CommandSender $sender) : void{
		$world = $sender instanceof Player ? $sender->getWorld() : $sender->getServer()->getWorldManager()->getDefaultWorld() ?? throw new AssumptionFailedError("No default world");
		$sender->sendMessage("The weather in " . $world->getFolderName() . " is " . strtolower($world->getWeather()->name) . (
			$world->isWeatherCycleEnabled() ? " for " . $world->getWeatherDuration() . " more ticks" : " until it is changed"
		));
	}

	private function change(CommandSender $sender, Weather $weather, ?int $duration = null) : void{
		$worlds = $sender instanceof Player ? [$sender->getWorld()] : $sender->getServer()->getWorldManager()->getWorlds();
		$changed = 0;
		foreach($worlds as $world){
			if($world->setWeather($weather, $duration)){
				++$changed;
			}
		}
		if($changed === 0){
			$sender->sendMessage(TextFormat::RED . "The weather change was cancelled");
			return;
		}
		Command::broadcastCommandMessage($sender, "Changed the weather to " . strtolower($weather->name));
	}
}
