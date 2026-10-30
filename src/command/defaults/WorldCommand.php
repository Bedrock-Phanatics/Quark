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
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\utils\TextFormat;
use quark\world\format\Chunk;
use quark\world\generator\Generator;
use quark\world\generator\GeneratorManager;
use quark\world\World;
use quark\world\WorldCreationOptions;
use quark\world\WorldException;
use quark\world\WorldManager;
use quark\YmlServerProperties;
use function array_keys;
use function array_shift;
use function array_slice;
use function array_values;
use function ceil;
use function count;
use function implode;
use function is_numeric;
use function ksort;
use function max;
use function strtolower;
use const SORT_STRING;

class WorldCommand extends VanillaCommand{

	/** @var array<int, true> */
	private array $pendingTeleports = [];

	public function __construct(){
		parent::__construct(
			"world",
			KnownTranslationFactory::quark_command_world_description(),
			KnownTranslationFactory::quark_command_world_usage(),
			["worlds"]
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_WORLD,
			DefaultPermissionNames::COMMAND_WORLD_LIST,
			DefaultPermissionNames::COMMAND_WORLD_INFO,
			DefaultPermissionNames::COMMAND_WORLD_CREATE,
			DefaultPermissionNames::COMMAND_WORLD_LOAD,
			DefaultPermissionNames::COMMAND_WORLD_UNLOAD,
			DefaultPermissionNames::COMMAND_WORLD_TELEPORT_SELF,
			DefaultPermissionNames::COMMAND_WORLD_TELEPORT_OTHER
		]);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args) : bool{
		if(count($args) === 0){
			throw new InvalidCommandSyntaxException();
		}

		$sub = strtolower(array_shift($args));
		try{
			return match($sub){
				"list" => $this->executeList($sender, $args),
				"info" => $this->executeInfo($sender, $args),
				"create" => $this->executeCreate($sender, $args),
				"load" => $this->executeLoad($sender, $args),
				"unload" => $this->executeUnload($sender, $args),
				"tp", "teleport" => $this->executeTeleport($sender, $args),
				default => throw new InvalidCommandSyntaxException()
			};
		}catch(\InvalidArgumentException $e){
			$sender->sendMessage(TextFormat::RED . $e->getMessage());
			return true;
		}
	}

	/**
	 * @param string[] $args
	 */
	private function executeList(CommandSender $sender, array $args) : bool{
		if(!$this->testPermission($sender, DefaultPermissionNames::COMMAND_WORLD_LIST)){
			return true;
		}

		$page = 1;
		if(isset($args[0]) && is_numeric($args[0])){
			$page = max(1, (int) $args[0]);
		}

		$worldManager = $sender->getServer()->getWorldManager();
		$defaultWorld = $worldManager->getDefaultWorld();

		$loadedEntries = [];
		foreach($worldManager->getWorlds() as $world){
			$name = $world->getFolderName();
			$isDefault = ($world === $defaultWorld);
			$playerCount = count($world->getPlayers());
			$chunkCount = count($world->getLoadedChunks());

			$loadedEntries[$name] = TextFormat::GREEN . "[Loaded] " . TextFormat::WHITE . $name .
				TextFormat::GRAY . " (players: $playerCount, chunks: $chunkCount)" .
				($isDefault ? TextFormat::YELLOW . " [Default]" : "");
		}
		ksort($loadedEntries, SORT_STRING);

		$unloadedEntries = [];
		foreach($worldManager->getGeneratedWorlds() as $generatedName){
			if(!isset($loadedEntries[$generatedName])){
				$unloadedEntries[$generatedName] = TextFormat::RED . "[Unloaded] " . TextFormat::WHITE . $generatedName . TextFormat::GRAY . " (format unchecked)";
			}
		}
		ksort($unloadedEntries, SORT_STRING);

		$allEntries = [...array_values($loadedEntries), ...array_values($unloadedEntries)];
		$totalCount = count($allEntries);

		if($totalCount === 0){
			$sender->sendMessage(TextFormat::YELLOW . "No worlds found.");
			return true;
		}

		$perPage = 5;
		$maxPages = (int) ceil($totalCount / $perPage);
		if($maxPages < 1){
			$maxPages = 1;
		}
		if($page > $maxPages){
			$page = $maxPages;
		}

		$slice = array_slice($allEntries, ($page - 1) * $perPage, $perPage);

		$sender->sendMessage(TextFormat::GOLD . "--- World List (Page $page of $maxPages, $totalCount total) ---");
		foreach($slice as $line){
			$sender->sendMessage($line);
		}
		if($page < $maxPages){
			$sender->sendMessage(TextFormat::GRAY . "Use /world list " . ($page + 1) . " to view the next page.");
		}

		return true;
	}

	/**
	 * @param string[] $args
	 */
	private function executeInfo(CommandSender $sender, array $args) : bool{
		if(!$this->testPermission($sender, DefaultPermissionNames::COMMAND_WORLD_INFO)){
			return true;
		}

		if(isset($args[0])){
			$name = $args[0];
		}elseif($sender instanceof Player){
			$name = $sender->getWorld()->getFolderName();
		}else{
			$sender->sendMessage(KnownTranslationFactory::commands_generic_usage(
				KnownTranslationFactory::quark_command_world_info_usage()
			));
			return true;
		}

		$worldManager = $sender->getServer()->getWorldManager();
		$name = $worldManager->resolveWorldName($name) ?? $name;
		$world = $worldManager->getWorldByName($name);

		if($world !== null){
			$sender->sendMessage(TextFormat::GOLD . "--- World Info: " . TextFormat::WHITE . $world->getDisplayName() . TextFormat::GOLD . " ---");
			$sender->sendMessage(TextFormat::GOLD . "Folder Name: " . TextFormat::WHITE . $world->getFolderName());
			$isDefault = ($world === $worldManager->getDefaultWorld());
			$sender->sendMessage(TextFormat::GOLD . "Status: " . TextFormat::GREEN . "Loaded" . ($isDefault ? TextFormat::YELLOW . " [Default]" : ""));
			$sender->sendMessage(TextFormat::GOLD . "Players: " . TextFormat::WHITE . count($world->getPlayers()));
			$sender->sendMessage(TextFormat::GOLD . "Loaded Chunks: " . TextFormat::WHITE . count($world->getLoadedChunks()));
			$sender->sendMessage(TextFormat::GOLD . "Entities: " . TextFormat::WHITE . count($world->getEntities()));
			$worldData = $world->getProvider()->getWorldData();
			$sender->sendMessage(TextFormat::GOLD . "Generator: " . TextFormat::WHITE . $worldData->getGenerator());
			$sender->sendMessage(TextFormat::GOLD . "Seed: " . TextFormat::WHITE . $worldData->getSeed());
			$diffString = match($world->getDifficulty()){
				World::DIFFICULTY_PEACEFUL => "Peaceful",
				World::DIFFICULTY_EASY => "Easy",
				World::DIFFICULTY_NORMAL => "Normal",
				World::DIFFICULTY_HARD => "Hard",
				default => "Unknown"
			};
			$sender->sendMessage(TextFormat::GOLD . "Difficulty: " . TextFormat::WHITE . $diffString);
			$sender->sendMessage(TextFormat::GOLD . "Time: " . TextFormat::WHITE . $world->getTime());
			$spawn = $world->getSpawnLocation();
			$sender->sendMessage(TextFormat::GOLD . "Spawn: " . TextFormat::WHITE . "X: {$spawn->getX()}, Y: {$spawn->getY()}, Z: {$spawn->getZ()}");
			$sender->sendMessage(TextFormat::GOLD . "Auto-Save: " . TextFormat::WHITE . ($world->getAutoSave() ? "Enabled" : "Disabled"));
			return true;
		}

		if(!$worldManager->isWorldGenerated($name)){
			$sender->sendMessage(TextFormat::RED . "World '$name' does not exist.");
			return true;
		}

		$worldPath = $worldManager->getWorldPath($name);
		$sender->sendMessage(TextFormat::GOLD . "--- World Info: " . TextFormat::WHITE . $name . TextFormat::GOLD . " ---");
		$sender->sendMessage(TextFormat::GOLD . "Folder Name: " . TextFormat::WHITE . $name);
		$sender->sendMessage(TextFormat::GOLD . "Status: " . TextFormat::RED . "Unloaded");
		$sender->sendMessage(TextFormat::GOLD . "Path: " . TextFormat::WHITE . $worldPath);

		$providers = $worldManager->getProviderManager()->getMatchingProviders($worldPath);
		$formatNames = count($providers) > 0 ? implode(", ", array_keys($providers)) : "Unknown";
		$sender->sendMessage(TextFormat::GOLD . "Format: " . TextFormat::WHITE . $formatNames);

		if(count($providers) > 0){
			$provider = null;
			try{
				$providerClass = array_values($providers)[0];
				$provider = $providerClass->fromPath($worldPath, new \PrefixedLogger($sender->getServer()->getLogger(), "World Info: $name"));
				$unloadedData = $provider->getWorldData();
				$sender->sendMessage(TextFormat::GOLD . "Generator: " . TextFormat::WHITE . $unloadedData->getGenerator());
				$sender->sendMessage(TextFormat::GOLD . "Seed: " . TextFormat::WHITE . $unloadedData->getSeed());
			}catch(\Throwable){
				// Unable to inspect metadata without error; format was still reported
			}finally{
				$provider?->close();
			}
		}

		$worldsConfig = (array) $sender->getServer()->getConfigGroup()->getProperty(YmlServerProperties::WORLDS, []);
		$preloaded = isset($worldsConfig[$name]);
		$sender->sendMessage(TextFormat::GOLD . "Startup Preload: " . ($preloaded ? TextFormat::GREEN . "Yes (configured in quark.yml)" : TextFormat::GRAY . "No"));

		return true;
	}

	/**
	 * @param string[] $args
	 */
	private function executeCreate(CommandSender $sender, array $args) : bool{
		if(!$this->testPermission($sender, DefaultPermissionNames::COMMAND_WORLD_CREATE)){
			return true;
		}

		if(!isset($args[0])){
			$sender->sendMessage(KnownTranslationFactory::commands_generic_usage(
				KnownTranslationFactory::quark_command_world_create_usage()
			));
			return true;
		}

		$name = $args[0];
		if(!WorldManager::isValidWorldName($name)){
			$sender->sendMessage(TextFormat::RED . "Invalid world name '$name'. World names can only contain alphanumeric characters, underscores, hyphens, dots, and spaces, and must not start or end with a dot or space.");
			return true;
		}

		$worldManager = $sender->getServer()->getWorldManager();

		if($worldManager->getWorldByName($name) !== null){
			$sender->sendMessage(TextFormat::RED . "World '$name' is already loaded.");
			return true;
		}

		if($worldManager->isWorldNameCollision($name)){
			$sender->sendMessage(TextFormat::RED . "A world with the name '$name' already exists. Use '/world load $name' to load it.");
			return true;
		}

		$generatorName = $args[1] ?? "normal";
		$generatorEntry = GeneratorManager::getInstance()->getGenerator($generatorName);
		if($generatorEntry === null){
			$sender->sendMessage(TextFormat::RED . "Unknown generator '$generatorName'. Available: " . implode(", ", GeneratorManager::getInstance()->getGeneratorList()));
			return true;
		}

		$seed = isset($args[2]) ? Generator::convertSeed($args[2]) : null;

		$options = WorldCreationOptions::create()
			->setGeneratorClass($generatorEntry->getGeneratorClass())
			->setDifficulty($sender->getServer()->getDifficulty());

		if($seed !== null){
			$options->setSeed($seed);
		}
		$spawnPos = $generatorEntry->getSpawnPosition($options->getSeed());
		if($spawnPos !== null){
			$options->setSpawnPosition($spawnPos);
		}

		$success = $worldManager->generateWorld($name, $options, false);
		if($success){
			Command::broadcastCommandMessage($sender, TextFormat::GREEN . "World '$name' created successfully (Generator: $generatorName, Seed: {$options->getSeed()}).");
		}else{
			$sender->sendMessage(TextFormat::RED . "Failed to create world '$name'.");
		}

		return true;
	}

	/**
	 * @param string[] $args
	 */
	private function executeLoad(CommandSender $sender, array $args) : bool{
		if(!$this->testPermission($sender, DefaultPermissionNames::COMMAND_WORLD_LOAD)){
			return true;
		}

		if(!isset($args[0])){
			$sender->sendMessage(KnownTranslationFactory::commands_generic_usage(
				KnownTranslationFactory::quark_command_world_load_usage()
			));
			return true;
		}

		$name = $args[0];
		$worldManager = $sender->getServer()->getWorldManager();
		$name = $worldManager->resolveWorldName($name) ?? $name;
		$loadedWorld = $worldManager->getWorldByName($name);
		if($loadedWorld !== null){
			$sender->sendMessage(TextFormat::YELLOW . "World '{$loadedWorld->getFolderName()}' is already loaded.");
			return true;
		}

		if(!$worldManager->isWorldGenerated($name)){
			$sender->sendMessage(TextFormat::RED . "World '$name' does not exist.");
			return true;
		}

		$success = $worldManager->loadWorld($name, true);
		if($success){
			Command::broadcastCommandMessage($sender, TextFormat::GREEN . "World '$name' loaded successfully.");
		}else{
			$sender->sendMessage(TextFormat::RED . "Failed to load world '$name'. Check console logs for details.");
		}

		return true;
	}

	/**
	 * @param string[] $args
	 */
	private function executeUnload(CommandSender $sender, array $args) : bool{
		if(!$this->testPermission($sender, DefaultPermissionNames::COMMAND_WORLD_UNLOAD)){
			return true;
		}

		if(!isset($args[0])){
			$sender->sendMessage(KnownTranslationFactory::commands_generic_usage(
				KnownTranslationFactory::quark_command_world_unload_usage()
			));
			return true;
		}

		$name = $args[0];
		$worldManager = $sender->getServer()->getWorldManager();
		$name = $worldManager->resolveWorldName($name) ?? $name;
		$world = $worldManager->getWorldByName($name);
		if($world === null){
			$sender->sendMessage(TextFormat::RED . "World '$name' is not loaded.");
			return true;
		}

		if($world === $worldManager->getDefaultWorld()){
			$sender->sendMessage(TextFormat::RED . "The default world cannot be unloaded.");
			return true;
		}

		if($world->isDoingTick()){
			$sender->sendMessage(TextFormat::RED . "Cannot unload world '{$world->getFolderName()}' during world tick.");
			return true;
		}

		$folderName = $world->getFolderName();
		$success = $worldManager->unloadWorld($world);
		if($success){
			Command::broadcastCommandMessage($sender, TextFormat::GREEN . "World '$folderName' unloaded successfully.");
		}else{
			$sender->sendMessage(TextFormat::RED . "Unable to unload world '$folderName'; an event or player evacuation prevented unloading.");
		}

		return true;
	}

	/**
	 * @param string[] $args
	 */
	private function executeTeleport(CommandSender $sender, array $args) : bool{
		if(!isset($args[0])){
			$sender->sendMessage(KnownTranslationFactory::commands_generic_usage(
				KnownTranslationFactory::quark_command_world_tp_usage()
			));
			return true;
		}

		$worldName = $args[0];
		$target = $this->fetchPermittedPlayerTarget($sender, $args[1] ?? null, DefaultPermissionNames::COMMAND_WORLD_TELEPORT_SELF, DefaultPermissionNames::COMMAND_WORLD_TELEPORT_OTHER);
		if($target === null){
			return true;
		}

		$targetId = $target->getId();
		if(isset($this->pendingTeleports[$targetId])){
			$sender->sendMessage(TextFormat::RED . "A teleport is already in progress for {$target->getName()}.");
			return true;
		}

		$worldManager = $sender->getServer()->getWorldManager();
		$worldName = $worldManager->resolveWorldName($worldName) ?? $worldName;
		$targetWorld = $worldManager->getWorldByName($worldName);
		if($targetWorld === null){
			if($worldManager->isWorldGenerated($worldName)){
				$sender->sendMessage(TextFormat::RED . "World '$worldName' is not loaded. Use '/world load $worldName' first.");
			}else{
				$sender->sendMessage(TextFormat::RED . "World '$worldName' does not exist.");
			}
			return true;
		}

		$spawnLoc = $targetWorld->getSpawnLocation();
		$chunkX = $spawnLoc->getFloorX() >> Chunk::COORD_BIT_SIZE;
		$chunkZ = $spawnLoc->getFloorZ() >> Chunk::COORD_BIT_SIZE;

		if($targetWorld->isChunkPopulated($chunkX, $chunkZ)){
			try{
				$safeSpawn = $targetWorld->getSafeSpawn($spawnLoc);
			}catch(WorldException){
				$sender->sendMessage(TextFormat::RED . "Unable to find a safe spawn in world '{$targetWorld->getDisplayName()}'.");
				return true;
			}

			if($target->teleport($safeSpawn)){
				if($sender === $target){
					$sender->sendMessage(TextFormat::GREEN . "Teleported to world '{$targetWorld->getDisplayName()}'.");
				}else{
					$sender->sendMessage(TextFormat::GREEN . "Teleported {$target->getName()} to world '{$targetWorld->getDisplayName()}'.");
					$target->sendMessage(TextFormat::GREEN . "Teleported to world '{$targetWorld->getDisplayName()}'.");
				}
			}else{
				$sender->sendMessage(TextFormat::RED . "Teleport to world '{$targetWorld->getDisplayName()}' was cancelled.");
			}
			return true;
		}

		$this->pendingTeleports[$targetId] = true;
		$sender->sendMessage(TextFormat::YELLOW . "Generating spawn terrain for world '{$targetWorld->getDisplayName()}', please wait...");
		if($sender !== $target){
			$target->sendMessage(TextFormat::YELLOW . "Generating spawn terrain for world '{$targetWorld->getDisplayName()}', please wait...");
		}

		$spawnLoc = $spawnLoc->asVector3();
		$senderRef = \WeakReference::create($sender);
		$targetRef = \WeakReference::create($target);
		$worldRef = \WeakReference::create($targetWorld);
		try{
			$targetWorld->requestChunkPopulation($chunkX, $chunkZ, null)->onCompletion(
				function() use ($senderRef, $targetRef, $worldRef, $targetId, $spawnLoc, $chunkX, $chunkZ) : void{
					unset($this->pendingTeleports[$targetId]);
					$sender = $senderRef->get();
					$target = $targetRef->get();
					$targetWorld = $worldRef->get();
					if($target === null || $targetWorld === null || !$target->isConnected() || $target->isClosed() || !$targetWorld->isLoaded()){
						return;
					}

					if(!$targetWorld->isChunkPopulated($chunkX, $chunkZ)){
						if($sender !== null && self::isSenderValid($sender)){
							$sender->sendMessage(TextFormat::RED . "Spawn terrain is not populated.");
						}
						return;
					}
					try{
						$safeSpawn = $targetWorld->getSafeSpawn($spawnLoc);
					}catch(WorldException){
						if($sender !== null && self::isSenderValid($sender)){
							$sender->sendMessage(TextFormat::RED . "Unable to find a safe spawn in world '{$targetWorld->getDisplayName()}'.");
						}
						return;
					}

					if($target->teleport($safeSpawn)){
						if($sender !== null && $sender !== $target && self::isSenderValid($sender)){
							$sender->sendMessage(TextFormat::GREEN . "Teleported {$target->getName()} to world '{$targetWorld->getDisplayName()}'.");
						}
						$target->sendMessage(TextFormat::GREEN . "Teleported to world '{$targetWorld->getDisplayName()}'.");
					}else{
						if($sender !== null && self::isSenderValid($sender)){
							$sender->sendMessage(TextFormat::RED . "Teleport to world '{$targetWorld->getDisplayName()}' was cancelled.");
						}
					}
				},
				function() use ($senderRef, $worldRef, $targetId) : void{
					unset($this->pendingTeleports[$targetId]);
					$sender = $senderRef->get();
					$targetWorld = $worldRef->get();
					if($sender !== null && self::isSenderValid($sender)){
						$sender->sendMessage(TextFormat::RED . "Failed to generate spawn terrain for world '" . ($targetWorld?->getDisplayName() ?? "unloaded") . "'.");
					}
				}
			);
		}catch(\Throwable $e){
			unset($this->pendingTeleports[$targetId]);
			$sender->sendMessage(TextFormat::RED . "Failed to start terrain generation for world '{$targetWorld->getDisplayName()}': " . $e->getMessage());
		}

		return true;
	}

	private static function isSenderValid(CommandSender $sender) : bool{
		return !($sender instanceof Player) || ($sender->isConnected() && !$sender->isClosed());
	}
}
