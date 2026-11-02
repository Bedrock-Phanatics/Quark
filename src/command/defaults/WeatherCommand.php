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

use quark\command\Command;
use quark\command\CommandSender;
use quark\command\utils\InvalidCommandSyntaxException;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\world\Weather;
use function count;
use function preg_match;
use function strtolower;

final class WeatherCommand extends VanillaCommand{
	public const MAX_DURATION = 1000000;

	public function __construct(){
		parent::__construct("weather", "Changes the weather", "/weather <clear|rain|thunder> [duration in ticks] OR /weather query");
		$this->setPermission(DefaultPermissionNames::COMMAND_WEATHER);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 1 || count($args) > 2){
			throw new InvalidCommandSyntaxException();
		}

		$mode = strtolower($args[0]);
		if($mode === "query"){
			$world = $sender instanceof Player ? $sender->getWorld() : $sender->getServer()->getWorldManager()->getDefaultWorld();
			if($world === null || count($args) !== 1){
				throw new InvalidCommandSyntaxException();
			}
			$sender->sendMessage("The weather in " . $world->getFolderName() . " is " . strtolower($world->getWeather()->name) . " for " . $world->getWeatherDuration() . " more ticks");
			return true;
		}

		$weather = match($mode){
			"clear" => Weather::CLEAR,
			"rain" => Weather::RAIN,
			"thunder" => Weather::THUNDER,
			default => throw new InvalidCommandSyntaxException()
		};
		$duration = null;
		if(isset($args[1])){
			if(preg_match('/^[1-9][0-9]{0,6}$/D', $args[1]) !== 1 || ($duration = (int) $args[1]) > self::MAX_DURATION){
				throw new InvalidCommandSyntaxException();
			}
		}

		$worlds = $sender instanceof Player ? [$sender->getWorld()] : $sender->getServer()->getWorldManager()->getWorlds();
		foreach($worlds as $world){
			$world->setWeather($weather, $duration);
		}
		Command::broadcastCommandMessage($sender, "Changed the weather to " . $mode);
		return true;
	}
}
