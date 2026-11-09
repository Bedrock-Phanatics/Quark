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

use quark\command\argument\ArgumentParseException;
use quark\command\argument\CommandEnum;
use quark\command\argument\EnumArgument;
use quark\command\argument\MappedArgument;
use quark\command\argument\PlayerArgument;
use quark\command\argument\StringArgument;
use quark\command\Command;
use quark\command\CommandSender;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\utils\TextFormat;
use quark\world\format\io\WorldMigration;
use quark\world\generator\Generator;
use quark\world\generator\GeneratorManager;
use quark\world\generator\GeneratorManagerEntry;
use quark\world\World;
use quark\world\WorldCreationOptions;
use quark\world\WorldException;
use function count;
use function implode;

final class WorldCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct("world", "Manages worlds");
		$this->setPermission(DefaultPermissionNames::COMMAND_WORLD);

		$loaded = fn() => new MappedArgument("world", static function(string $name, CommandSender $sender) : World{
			return $sender->getServer()->getWorldManager()->getWorldByName($name) ??
				throw new ArgumentParseException(TextFormat::RED . "World \"$name\" is not loaded");
		}, World::class);
		$name = fn(string $argument = "name") => new StringArgument($argument);

		$this->syntax()->handler($this->list(...));
		$this->syntax("list")->handler($this->list(...));
		$this->syntax("tp")->registerArgument($loaded())->handler($this->teleportSelf(...));
		$this->syntax("tp")->registerArguments([$loaded(), new PlayerArgument("player", partial: true, self: true)])->handler($this->teleportPlayer(...));
		$this->syntax("load")->registerArgument($name())->handler($this->load(...));
		$this->syntax("unload")->registerArgument($loaded())->handler($this->unload(...));
		$this->syntax("create")->registerArguments([
			$name(),
			new MappedArgument("generator", static function(string $generator) : GeneratorManagerEntry{
				return GeneratorManager::getInstance()->getGenerator($generator) ??
					throw new ArgumentParseException(TextFormat::RED . "Unknown generator \"$generator\", use one of " . implode(", ", GeneratorManager::getInstance()->getGeneratorList()));
			}, GeneratorManagerEntry::class, optional: true),
			new StringArgument("seed", optional: true)
		])->handler($this->create(...));
		$this->syntax("copy")->registerArguments([$name("source"), $name("target")])->handler($this->copy(...));
		$this->syntax("delete")->registerArgument($name())->handler($this->delete(...));
		$this->syntax("convert")->registerArguments([
			$name(),
			new EnumArgument("format", CommandEnum::fromValues("WorldFormat", [WorldMigration::LEVELDB, WorldMigration::ZIGRITEDB]))
		])->handler($this->convert(...));
		$this->syntax("verify")->registerArgument($name())->handler($this->verify(...));
	}

	private function list(CommandSender $sender) : void{
		$manager = $sender->getServer()->getWorldManager();
		$names = [];
		foreach($manager->getWorldNames() as $worldName){
			$world = $manager->getWorldByName($worldName);
			$names[] = $world === null ? TextFormat::GRAY . $worldName : TextFormat::GREEN . $worldName . TextFormat::WHITE . " (" . count($world->getPlayers()) . ")";
		}
		$sender->sendMessage("Worlds: " . implode(TextFormat::WHITE . ", ", $names));
	}

	private function teleportSelf(Player $sender, World $world) : void{
		$this->teleport($sender, $sender, $world);
	}

	private function teleportPlayer(CommandSender $sender, World $world, Player $player) : void{
		if($this->canTarget($sender, $player, DefaultPermissionNames::COMMAND_WORLD, DefaultPermissionNames::COMMAND_WORLD)){
			$this->teleport($sender, $player, $world);
		}
	}

	private function teleport(CommandSender $sender, Player $player, World $world) : void{
		$player->teleport($world->getSafeSpawn());
		Command::broadcastCommandMessage($sender, "Teleported " . $player->getName() . " to " . $world->getFolderName());
	}

	private function load(CommandSender $sender, string $name) : void{
		$this->guard($sender, function() use ($sender, $name) : void{
			$sender->sendMessage($sender->getServer()->getWorldManager()->loadWorld($name, true) ? "Loaded world \"$name\"" : TextFormat::RED . "World \"$name\" could not be loaded");
		});
	}

	private function unload(CommandSender $sender, World $world) : void{
		$name = $world->getFolderName();
		$this->guard($sender, function() use ($sender, $world, $name) : void{
			if($sender->getServer()->getWorldManager()->unloadWorld($world)){
				Command::broadcastCommandMessage($sender, "Unloaded world \"$name\"");
			}else{
				$sender->sendMessage(TextFormat::RED . "Unloading \"$name\" was cancelled");
			}
		});
	}

	private function create(CommandSender $sender, string $name, ?GeneratorManagerEntry $generator = null, ?string $seed = null) : void{
		$server = $sender->getServer();
		$generator ??= GeneratorManager::getInstance()->getGenerator("default");
		if($generator === null){
			$sender->sendMessage(TextFormat::RED . "Unknown generator \"default\", use one of " . implode(", ", GeneratorManager::getInstance()->getGeneratorList()));
			return;
		}
		$options = WorldCreationOptions::create()
			->setGeneratorClass($generator->getGeneratorClass())
			->setDifficulty($server->getDifficulty());
		if($seed !== null && ($converted = Generator::convertSeed($seed)) !== null){
			$options->setSeed($converted);
		}
		if(($spawn = $generator->getSpawnPosition($options->getSeed())) !== null){
			$options->setSpawnPosition($spawn);
		}
		$this->guard($sender, function() use ($sender, $name, $options) : void{
			if($sender->getServer()->getWorldManager()->generateWorld($name, $options)){
				Command::broadcastCommandMessage($sender, "Created world \"$name\"");
			}else{
				$sender->sendMessage(TextFormat::RED . "World \"$name\" already exists or the name is invalid");
			}
		});
	}

	private function copy(CommandSender $sender, string $source, string $target) : void{
		$this->guard($sender, function() use ($sender, $source, $target) : void{
			$sender->getServer()->getWorldManager()->copyWorld($source, $target)->observe(
				fn() => $this->reply($sender, "Copied world \"$source\" to \"$target\""),
				fn(\Throwable $e) => $this->fail($sender, $e)
			);
			$sender->sendMessage("Copying world \"$source\" to \"$target\"...");
		});
	}

	private function delete(CommandSender $sender, string $name) : void{
		$this->guard($sender, function() use ($sender, $name) : void{
			$sender->getServer()->getWorldManager()->deleteWorld($name)->observe(
				fn() => $this->reply($sender, "Deleted world \"$name\""),
				fn(\Throwable $e) => $this->fail($sender, $e)
			);
			$sender->sendMessage("Deleting world \"$name\"...");
		});
	}

	private function convert(CommandSender $sender, string $name, string $format) : void{
		$this->guard($sender, function() use ($sender, $name, $format) : void{
			$sender->getServer()->getWorldManager()->convertWorld($name, $format)->observe(
				fn(array $result) => $this->reply($sender, "Converted world \"$name\" to $format: " . $result["chunk_records"] . " chunk records, " . $result["other_records"] . " other records and " . $result["files"] . " world files were copied and checked. The original is at " . $result["backup"]),
				fn(\Throwable $e) => $this->fail($sender, $e)
			);
			$sender->sendMessage("Converting world \"$name\" to $format, it can't be loaded until this finishes...");
		});
	}

	private function verify(CommandSender $sender, string $name) : void{
		$this->guard($sender, function() use ($sender, $name) : void{
			$sender->getServer()->getWorldManager()->verifyWorld($name)->observe(
				fn(array $result) => $this->reply($sender, "World \"$name\" passed its checks: " . $result["regions"] . " regions"),
				fn(\Throwable $e) => $this->fail($sender, $e)
			);
			$sender->sendMessage("Verifying world \"$name\"...");
		});
	}

	/**
	 * @phpstan-param \Closure() : void $operation
	 */
	private function guard(CommandSender $sender, \Closure $operation) : void{
		try{
			$operation();
		}catch(\InvalidArgumentException | WorldException $e){
			$sender->sendMessage(TextFormat::RED . $e->getMessage());
		}
	}

	private function reply(CommandSender $sender, string $message) : void{
		if(!$sender instanceof Player || $sender->isConnected()){
			$sender->sendMessage($message);
		}
	}

	private function fail(CommandSender $sender, \Throwable $e) : void{
		$sender->getServer()->getLogger()->error($e->getMessage());
		if($sender instanceof Player){
			$this->reply($sender, TextFormat::RED . $e->getMessage());
		}
	}
}
