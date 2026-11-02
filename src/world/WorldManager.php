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

namespace quark\world;

use quark\entity\Entity;
use quark\event\world\WorldInitEvent;
use quark\event\world\WorldLoadEvent;
use quark\event\world\WorldUnloadEvent;
use quark\lang\KnownTranslationFactory;
use quark\player\ChunkSelector;
use quark\promise\Promise;
use quark\promise\PromiseResolver;
use quark\Server;
use quark\utils\Utils;
use quark\world\format\Chunk;
use quark\world\format\io\exception\CorruptedWorldException;
use quark\world\format\io\exception\UnsupportedWorldFormatException;
use quark\world\format\io\exception\WorldInUseException;
use quark\world\format\io\FormatConverter;
use quark\world\format\io\WorldProviderManager;
use quark\world\format\io\WritableWorldProvider;
use quark\world\generator\GeneratorManager;
use quark\world\generator\InvalidGeneratorOptionsException;
use Symfony\Component\Filesystem\Path;
use function array_keys;
use function array_map;
use function array_shift;
use function assert;
use function count;
use function file_exists;
use function floor;
use function implode;
use function intdiv;
use function is_dir;
use function iterator_to_array;
use function microtime;
use function round;
use function scandir;
use function sort;
use function sprintf;
use function str_contains;
use function strval;
use function trim;
use const SCANDIR_SORT_NONE;
use const SORT_STRING;

class WorldManager{
	public const TICKS_PER_AUTOSAVE = 300 * Server::TARGET_TICKS_PER_SECOND;

	/**
	 * @var World[]
	 * @phpstan-var array<int, World>
	 */
	private array $worlds = [];
	/**
	 * @var World[]
	 * @phpstan-var array<string, World>
	 */
	private array $worldsByName = [];
	/**
	 * Folder names being copied or deleted, which cannot be loaded or generated until that finishes. Copy targets are
	 * marked true and hidden from getWorldNames() since they are still incomplete.
	 * @phpstan-var array<string, bool>
	 */
	private array $busyNames = [];
	private ?World $defaultWorld = null;

	private bool $autoSave = true;
	private int $autoSaveTicks = self::TICKS_PER_AUTOSAVE;
	private int $autoSaveTicker = 0;

	public function __construct(
		private Server $server,
		private string $dataPath,
		private WorldProviderManager $providerManager
	){}

	public function getProviderManager() : WorldProviderManager{
		return $this->providerManager;
	}

	/**
	 * @return World[]
	 * @phpstan-return array<int, World>
	 */
	public function getWorlds() : array{
		return $this->worlds;
	}

	public function getDefaultWorld() : ?World{
		return $this->defaultWorld;
	}

	/**
	 * Sets the default world to a different world
	 * This won't change the level-name property,
	 * it only affects the server on runtime
	 */
	public function setDefaultWorld(?World $world) : void{
		if($world === null || ($this->isWorldLoaded($world->getFolderName()) && $world !== $this->defaultWorld)){
			$this->defaultWorld = $world;
		}
	}

	public function isWorldLoaded(string $name) : bool{
		return isset($this->worldsByName[$name]);
	}

	public function getWorld(int $worldId) : ?World{
		return $this->worlds[$worldId] ?? null;
	}

	/**
	 * NOTE: This matches worlds based on the FOLDER name, NOT the display name.
	 */
	public function getWorldByName(string $name) : ?World{
		return $this->worldsByName[$name] ?? null;
	}

	/**
	 * Whether the name can be used as a world folder name. It must not be empty or lead outside the worlds directory.
	 */
	public function isValidWorldName(string $name) : bool{
		if(trim($name) === "" || str_contains($name, "\0")){
			return false;
		}
		$path = Path::join($this->dataPath, $name);
		return Path::isBasePath($this->dataPath, $path) && Path::canonicalize($path) !== Path::canonicalize($this->dataPath);
	}

	/**
	 * Returns the folder names of every generated world in the worlds directory, loaded or not, in sorted order.
	 *
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getWorldNames() : array{
		$names = [];
		foreach($this->worldsByName as $world){
			$names[$world->getFolderName()] = true;
		}
		$entries = scandir($this->dataPath, SCANDIR_SORT_NONE);
		foreach($entries === false ? [] : $entries as $name){
			if(
				$name !== "." && $name !== ".." && !isset($names[$name]) && !($this->busyNames[$name] ?? false) &&
				is_dir($path = Path::join($this->dataPath, $name)) && count($this->providerManager->getMatchingProviders($path . "/")) > 0
			){
				$names[$name] = true;
			}
		}
		$names = array_map(strval(...), array_keys($names));
		sort($names, SORT_STRING);
		return $names;
	}

	/**
	 * Copies an unloaded world to a new folder off the main thread. Both names stay reserved until the copy is done,
	 * and a failed copy leaves nothing behind.
	 *
	 * @phpstan-return Promise<null>
	 * @throws \InvalidArgumentException if the source is loaded or missing, or the target exists
	 */
	public function copyWorld(string $source, string $target) : Promise{
		$sourcePath = $this->checkUnloadedWorld($source);
		if(
			!$this->isValidWorldName($target) || isset($this->busyNames[$target]) || isset($this->worldsByName[$target]) ||
			file_exists($targetPath = $this->getWorldPath($target)) || Path::isBasePath($sourcePath, $targetPath)
		){
			throw new \InvalidArgumentException("Cannot copy world \"$source\" to \"$target\": the target is invalid or already exists");
		}
		return $this->runFilesTask(
			fn(\Closure $onDone) => new WorldFilesTask($sourcePath, $targetPath, $onDone),
			"copy world \"$source\" to \"$target\"",
			[$source => false, $target => true],
			fn() => $this->renameCopiedWorld($target, $targetPath)
		);
	}

	private function renameCopiedWorld(string $name, string $path) : void{
		$providers = $this->providerManager->getMatchingProviders($path);
		if(count($providers) !== 1){
			return;
		}
		try{
			$provider = array_shift($providers)->fromPath($path, new \quark\log\SourceLogger($this->server->getLogger(), "World Provider: $name"));
			try{
				$provider->getWorldData()->setName($name);
				$provider->getWorldData()->save();
			}finally{
				$provider->close();
			}
		}catch(\Throwable $e){
			//the copy itself is fine, it just keeps the old display name
			$this->server->getLogger()->warning("Could not rename copied world \"$name\": " . $e->getMessage());
		}
	}

	/**
	 * Deletes an unloaded world off the main thread. The name stays reserved until the files are gone.
	 *
	 * @phpstan-return Promise<null>
	 * @throws \InvalidArgumentException if the world is loaded or missing
	 */
	public function deleteWorld(string $name) : Promise{
		$path = $this->checkUnloadedWorld($name);
		return $this->runFilesTask(fn(\Closure $onDone) => new WorldFilesTask($path, null, $onDone), "delete world \"$name\"", [$name => false]);
	}

	private function checkUnloadedWorld(string $name) : string{
		if(!$this->isValidWorldName($name) || isset($this->busyNames[$name]) || !$this->isWorldGenerated($name)){
			throw new \InvalidArgumentException("World \"$name\" does not exist or is busy");
		}
		if(isset($this->worldsByName[$name])){
			throw new \InvalidArgumentException("World \"$name\" must be unloaded first");
		}
		return $this->getWorldPath($name);
	}

	/**
	 * @phpstan-param \Closure(\Closure(?string) : void) : WorldFilesTask $createTask
	 * @phpstan-param array<string, bool> $names names to reserve, mapped to whether they are hidden
	 * @phpstan-param (\Closure() : void)|null $beforeResolve
	 * @phpstan-return Promise<null>
	 */
	private function runFilesTask(\Closure $createTask, string $description, array $names, ?\Closure $beforeResolve = null) : Promise{
		/** @phpstan-var PromiseResolver<null> $resolver */
		$resolver = new PromiseResolver();
		$release = function() use ($names) : void{
			foreach(Utils::stringifyKeys($names) as $name => $_){
				unset($this->busyNames[$name]);
			}
		};
		foreach(Utils::stringifyKeys($names) as $name => $hidden){
			$this->busyNames[$name] = $hidden;
		}
		try{
			$this->server->getAsyncPool()->submitTask($createTask(function(?string $error) use ($resolver, $description, $release, $beforeResolve) : void{
				if($error === null && $beforeResolve !== null){
					$beforeResolve();
				}
				$release();
				if($error !== null){
					$this->server->getLogger()->error("Failed to $description: $error");
					$resolver->reject();
				}else{
					$resolver->resolve(null);
				}
			}));
		}catch(\Throwable $e){
			$release();
			throw $e;
		}
		return $resolver->getPromise();
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public function unloadWorld(World $world, bool $forceUnload = false) : bool{
		if(($this->worlds[$world->getId()] ?? null) !== $world){
			throw new \InvalidArgumentException("World \"" . $world->getFolderName() . "\" is not loaded");
		}
		if($world === $this->getDefaultWorld() && !$forceUnload){
			throw new \InvalidArgumentException("The default world cannot be unloaded while running, please switch worlds.");
		}
		if($world->isDoingTick()){
			throw new \InvalidArgumentException("Cannot unload a world during world tick");
		}

		$ev = new WorldUnloadEvent($world);
		$ev->call();

		if(!$forceUnload && $ev->isCancelled()){
			return false;
		}

		$this->server->getLogger()->info($this->server->getLanguage()->translate(KnownTranslationFactory::quark_level_unloading($world->getDisplayName())));
		if(count($world->getPlayers()) !== 0){
			try{
				$safeSpawn = $this->defaultWorld !== null && $this->defaultWorld !== $world ? $this->defaultWorld->getSafeSpawn() : null;
			}catch(WorldException $e){
				$safeSpawn = null;
			}
			foreach($world->getPlayers() as $player){
				if($safeSpawn === null){
					$player->disconnect("Forced default world unload");
				}else{
					$player->teleport($safeSpawn);
				}
			}
		}

		if($world === $this->defaultWorld){
			$this->defaultWorld = null;
		}
		unset($this->worlds[$world->getId()], $this->worldsByName[$world->getFolderName()]);

		$world->onUnload();
		return true;
	}

	/**
	 * Loads a world from the data directory
	 *
	 * @param bool $autoUpgrade Converts worlds to the default format if the world's format is not writable / deprecated
	 *
	 * @throws WorldException
	 */
	public function loadWorld(string $name, bool $autoUpgrade = false) : bool{
		if(!$this->isValidWorldName($name)){
			throw new \InvalidArgumentException("Invalid world name \"$name\"");
		}
		if($this->isWorldLoaded($name)){
			return true;
		}elseif(isset($this->busyNames[$name]) || !$this->isWorldGenerated($name)){
			return false;
		}

		$path = $this->getWorldPath($name);

		$providers = $this->providerManager->getMatchingProviders($path);
		if(count($providers) !== 1){
			$this->server->getLogger()->error($this->server->getLanguage()->translate(KnownTranslationFactory::quark_level_loadError(
				$name,
				count($providers) === 0 ?
					KnownTranslationFactory::quark_level_unknownFormat() :
					KnownTranslationFactory::quark_level_ambiguousFormat(implode(", ", array_keys($providers)))
			)));
			return false;
		}
		$providerClass = array_shift($providers);

		try{
			$provider = $providerClass->fromPath($path, new \quark\log\SourceLogger($this->server->getLogger(), "World Provider: $name"));
		}catch(CorruptedWorldException $e){
			$this->server->getLogger()->error($this->server->getLanguage()->translate(KnownTranslationFactory::quark_level_loadError(
				$name,
				KnownTranslationFactory::quark_level_corrupted($e->getMessage())
			)));
			return false;
		}catch(UnsupportedWorldFormatException $e){
			$this->server->getLogger()->error($this->server->getLanguage()->translate(KnownTranslationFactory::quark_level_loadError(
				$name,
				KnownTranslationFactory::quark_level_unsupportedFormat($e->getMessage())
			)));
			return false;
		}catch(WorldInUseException $e){
			$this->server->getLogger()->error($this->server->getLanguage()->translate(KnownTranslationFactory::quark_level_loadError(
				$name,
				KnownTranslationFactory::quark_level_inUse($e->getMessage())
			)));
			return false;
		}

		$generatorEntry = GeneratorManager::getInstance()->getGenerator($provider->getWorldData()->getGenerator());
		if($generatorEntry === null){
			$this->server->getLogger()->error($this->server->getLanguage()->translate(KnownTranslationFactory::quark_level_loadError(
				$name,
				KnownTranslationFactory::quark_level_unknownGenerator($provider->getWorldData()->getGenerator())
			)));
			return false;
		}
		try{
			$generatorEntry->validateGeneratorOptions($provider->getWorldData()->getGeneratorOptions());
		}catch(InvalidGeneratorOptionsException $e){
			$this->server->getLogger()->error($this->server->getLanguage()->translate(KnownTranslationFactory::quark_level_loadError(
				$name,
				KnownTranslationFactory::quark_level_invalidGeneratorOptions(
					$provider->getWorldData()->getGeneratorOptions(),
					$provider->getWorldData()->getGenerator(),
					$e->getMessage()
				)
			)));
			return false;
		}
		if(!($provider instanceof WritableWorldProvider)){
			if(!$autoUpgrade){
				throw new UnsupportedWorldFormatException("World \"$name\" is in an unsupported format and needs to be upgraded");
			}
			$this->server->getLogger()->notice($this->server->getLanguage()->translate(KnownTranslationFactory::quark_level_conversion_start($name)));

			$providerClass = $this->providerManager->getDefault();
			$converter = new FormatConverter($provider, $providerClass, Path::join($this->server->getFilesystemPaths()->backups(), "worlds"), $this->server->getLogger());
			$converter->execute();
			$provider = $providerClass->fromPath($path, new \quark\log\SourceLogger($this->server->getLogger(), "World Provider: $name"));

			$this->server->getLogger()->notice($this->server->getLanguage()->translate(KnownTranslationFactory::quark_level_conversion_finish($name, $converter->getBackupPath())));
		}

		$world = new World($this->server, $name, $provider, $this->server->getAsyncPool());

		$this->worlds[$world->getId()] = $world;
		$this->worldsByName[$name] = $world;
		$world->setAutoSave($this->autoSave);

		(new WorldLoadEvent($world))->call();

		return true;
	}

	/**
	 * Generates a new world if it does not exist
	 *
	 * @throws \InvalidArgumentException
	 */
	public function generateWorld(string $name, WorldCreationOptions $options, bool $backgroundGeneration = true) : bool{
		if(!$this->isValidWorldName($name) || isset($this->busyNames[$name]) || $this->isWorldGenerated($name)){
			return false;
		}

		$providerEntry = $this->providerManager->getDefault();

		$path = $this->getWorldPath($name);
		$providerEntry->generate($path, $name, $options);

		$world = new World($this->server, $name, $providerEntry->fromPath($path, new \quark\log\SourceLogger($this->server->getLogger(), "World Provider: $name")), $this->server->getAsyncPool());
		$this->worlds[$world->getId()] = $world;
		$this->worldsByName[$name] = $world;

		$world->setAutoSave($this->autoSave);

		(new WorldInitEvent($world))->call();

		(new WorldLoadEvent($world))->call();

		if($backgroundGeneration){
			$this->server->getLogger()->notice($this->server->getLanguage()->translate(KnownTranslationFactory::quark_level_backgroundGeneration($name)));

			$spawnLocation = $world->getSpawnLocation();
			$centerX = $spawnLocation->getFloorX() >> Chunk::COORD_BIT_SIZE;
			$centerZ = $spawnLocation->getFloorZ() >> Chunk::COORD_BIT_SIZE;

			$selected = iterator_to_array((new ChunkSelector())->selectChunks(8, $centerX, $centerZ), preserve_keys: false);
			$done = 0;
			$total = count($selected);
			foreach($selected as $index){
				World::getXZ($index, $chunkX, $chunkZ);
				$world->orderChunkPopulation($chunkX, $chunkZ, null)->onCompletion(
					static function() use ($world, &$done, $total) : void{
						$oldProgress = (int) floor(($done / $total) * 100);
						$newProgress = (int) floor((++$done / $total) * 100);
						if(intdiv($oldProgress, 10) !== intdiv($newProgress, 10) || $done === $total || $done === 1){
							$world->getLogger()->info($world->getServer()->getLanguage()->translate(KnownTranslationFactory::quark_level_spawnTerrainGenerationProgress(strval($done), strval($total), strval($newProgress))));
						}
					},
					static function() : void{
						//NOOP: we don't care if the world was unloaded
					});
			}
		}

		return true;
	}

	private function getWorldPath(string $name) : string{
		return Path::join($this->dataPath, $name) . "/"; //TODO: check if we still need the trailing dirsep (I'm a little scared to remove it)
	}

	public function isWorldGenerated(string $name) : bool{
		if(isset($this->worldsByName[$name])){
			return true;
		}
		return $this->isValidWorldName($name) && count($this->providerManager->getMatchingProviders($this->getWorldPath($name))) > 0;
	}

	/**
	 * Searches all worlds for the entity with the specified ID.
	 * Useful for tracking entities across multiple worlds without needing strong references.
	 */
	public function findEntity(int $entityId) : ?Entity{
		foreach($this->worlds as $world){
			assert($world->isLoaded());
			if(($entity = $world->getEntity($entityId)) instanceof Entity){
				return $entity;
			}
		}

		return null;
	}

	public function tick(int $currentTick) : void{
		foreach($this->worlds as $k => $world){
			if(!isset($this->worlds[$k])){
				// World unloaded during the tick of a world earlier in this loop, perhaps by plugin
				continue;
			}

			$worldTime = microtime(true);
			$world->doTick($currentTick);
			$tickMs = (microtime(true) - $worldTime) * 1000;
			$world->tickRateTime = $tickMs;
			if($tickMs >= Server::TARGET_SECONDS_PER_TICK * 1000){
				$world->getLogger()->debug(sprintf("Tick took too long: %gms (%g ticks)", $tickMs, round($tickMs / (Server::TARGET_SECONDS_PER_TICK * 1000), 2)));
			}
		}

		if($this->autoSave && ++$this->autoSaveTicker >= $this->autoSaveTicks){
			$this->autoSaveTicker = 0;
			$this->server->getLogger()->debug("[Auto Save] Saving worlds...");
			$start = microtime(true);
			$this->doAutoSave();
			$time = microtime(true) - $start;
			$this->server->getLogger()->debug("[Auto Save] Save completed in " . ($time >= 1 ? round($time, 3) . "s" : round($time * 1000) . "ms"));
		}
	}

	public function getAutoSave() : bool{
		return $this->autoSave;
	}

	public function setAutoSave(bool $value) : void{
		$this->autoSave = $value;
		foreach($this->worlds as $world){
			$world->setAutoSave($this->autoSave);
		}
	}

	/**
	 * Returns the period in ticks after which loaded worlds will be automatically saved to disk.
	 */
	public function getAutoSaveInterval() : int{
		return $this->autoSaveTicks;
	}

	public function setAutoSaveInterval(int $autoSaveTicks) : void{
		if($autoSaveTicks <= 0){
			throw new \InvalidArgumentException("Autosave ticks must be positive");
		}
		$this->autoSaveTicks = $autoSaveTicks;
	}

	private function doAutoSave() : void{
		foreach($this->worlds as $world){
			foreach($world->getPlayers() as $player){
				if($player->spawned){
					$player->save();
				}
			}
			$world->save(false);
		}
	}
}
