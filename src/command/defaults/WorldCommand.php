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
use quark\world\generator\Generator;
use quark\world\generator\GeneratorManager;
use quark\world\WorldCreationOptions;
use quark\world\WorldException;
use function array_shift;
use function count;
use function implode;
use function strtolower;

final class WorldCommand extends VanillaCommand{

	public function __construct(private Server $server){
		parent::__construct("world", "Manages worlds", "/world <list|tp|load|unload|create|copy|delete> [args]");
		$this->setPermission(DefaultPermissionNames::COMMAND_WORLD);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		$manager = $this->server->getWorldManager();
		$mode = strtolower(array_shift($args) ?? "list");
		$name = $args[0] ?? null;

		try{
			switch($mode){
				case "list":
					$names = [];
					foreach($manager->getWorldNames() as $worldName){
						$world = $manager->getWorldByName($worldName);
						$names[] = $world === null ? TextFormat::GRAY . $worldName : TextFormat::GREEN . $worldName . TextFormat::WHITE . " (" . count($world->getPlayers()) . ")";
					}
					$sender->sendMessage("Worlds: " . implode(TextFormat::WHITE . ", ", $names));
					return true;

				case "tp":
					if($name === null || count($args) > 2){
						throw new InvalidCommandSyntaxException();
					}
					$world = $manager->getWorldByName($name);
					if($world === null){
						$sender->sendMessage(TextFormat::RED . "World \"$name\" is not loaded");
						return true;
					}
					$player = $this->fetchPermittedPlayerTarget($this->server, $sender, $args[1] ?? null, DefaultPermissionNames::COMMAND_WORLD, DefaultPermissionNames::COMMAND_WORLD);
					if($player !== null){
						$player->teleport($world->getSafeSpawn());
						Command::broadcastCommandMessage($this->server, $sender, "Teleported " . $player->getName() . " to " . $name);
					}
					return true;

				case "load":
					if($name === null || count($args) !== 1){
						throw new InvalidCommandSyntaxException();
					}
					$sender->sendMessage($manager->loadWorld($name, true) ? "Loaded world \"$name\"" : TextFormat::RED . "World \"$name\" could not be loaded");
					return true;

				case "unload":
					if($name === null || count($args) !== 1){
						throw new InvalidCommandSyntaxException();
					}
					$world = $manager->getWorldByName($name);
					if($world === null){
						$sender->sendMessage(TextFormat::RED . "World \"$name\" is not loaded");
					}elseif($manager->unloadWorld($world)){
						Command::broadcastCommandMessage($this->server, $sender, "Unloaded world \"$name\"");
					}else{
						$sender->sendMessage(TextFormat::RED . "Unloading \"$name\" was cancelled");
					}
					return true;

				case "create":
					if($name === null || count($args) > 3){
						throw new InvalidCommandSyntaxException();
					}
					$generatorName = $args[1] ?? "default";
					$generator = GeneratorManager::getInstance()->getGenerator($generatorName);
					if($generator === null){
						$sender->sendMessage(TextFormat::RED . "Unknown generator \"$generatorName\", use one of " . implode(", ", GeneratorManager::getInstance()->getGeneratorList()));
						return true;
					}
					$options = WorldCreationOptions::create()
						->setGeneratorClass($generator->getGeneratorClass())
						->setDifficulty($this->server->getDifficulty());
					if(isset($args[2]) && ($seed = Generator::convertSeed($args[2])) !== null){
						$options->setSeed($seed);
					}
					if(($spawn = $generator->getSpawnPosition($options->getSeed())) !== null){
						$options->setSpawnPosition($spawn);
					}
					if($manager->generateWorld($name, $options)){
						Command::broadcastCommandMessage($this->server, $sender, "Created world \"$name\"");
					}else{
						$sender->sendMessage(TextFormat::RED . "World \"$name\" already exists or the name is invalid");
					}
					return true;

				case "copy":
					if($name === null || count($args) !== 2){
						throw new InvalidCommandSyntaxException();
					}
					$target = $args[1];
					$manager->copyWorld($name, $target)->onCompletion(
						fn() => $this->reply($sender, "Copied world \"$name\" to \"$target\""),
						fn() => $this->reply($sender, TextFormat::RED . "Copying world \"$name\" failed, see the console for details")
					);
					$sender->sendMessage("Copying world \"$name\" to \"$target\"...");
					return true;

				case "delete":
					if($name === null || count($args) !== 1){
						throw new InvalidCommandSyntaxException();
					}
					$manager->deleteWorld($name)->onCompletion(
						fn() => $this->reply($sender, "Deleted world \"$name\""),
						fn() => $this->reply($sender, TextFormat::RED . "Deleting world \"$name\" failed, see the console for details")
					);
					$sender->sendMessage("Deleting world \"$name\"...");
					return true;
			}
		}catch(\InvalidArgumentException | WorldException $e){
			$sender->sendMessage(TextFormat::RED . $e->getMessage());
			return true;
		}

		throw new InvalidCommandSyntaxException();
	}

	private function reply(CommandSender $sender, string $message) : void{
		if(!$sender instanceof Player || $sender->isConnected()){
			$sender->sendMessage($message);
		}
	}
}
