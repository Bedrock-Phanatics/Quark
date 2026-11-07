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
use quark\Server;
use quark\utils\TextFormat;
use quark\world\Weather;
use function count;
use function preg_match;
use function strtolower;

final class WeatherCommand extends VanillaCommand{
	public const MAX_DURATION = 1000000;

	public function __construct(private Server $server){
		parent::__construct("weather", "Changes the weather", "/weather <clear|rain|thunder> [duration in ticks] OR /weather query");
		$this->setPermission(DefaultPermissionNames::COMMAND_WEATHER);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 1 || count($args) > 2){
			throw new InvalidCommandSyntaxException();
		}

		$mode = strtolower($args[0]);
		if($mode === "query"){
			$world = $sender instanceof Player ? $sender->getWorld() : $this->server->getWorldManager()->getDefaultWorld();
			if($world === null || count($args) !== 1){
				throw new InvalidCommandSyntaxException();
			}
			$sender->sendMessage("The weather in " . $world->getFolderName() . " is " . strtolower($world->getWeather()->name) . (
				$world->isWeatherCycleEnabled() ? " for " . $world->getWeatherDuration() . " more ticks" : " until it is changed"
			));
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

		$worlds = $sender instanceof Player ? [$sender->getWorld()] : $this->server->getWorldManager()->getWorlds();
		$changed = 0;
		foreach($worlds as $world){
			if($world->setWeather($weather, $duration)){
				++$changed;
			}
		}
		if($changed === 0){
			$sender->sendMessage(TextFormat::RED . "The weather change was cancelled");
			return true;
		}
		Command::broadcastCommandMessage($this->server, $sender, "Changed the weather to " . $mode);
		return true;
	}
}
