<?php

declare(strict_types=1);

namespace pocketmine\world\redstone;

use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\world\Position;
use pocketmine\world\World;

final class RedstoneManager{

	private static ?self $instance = null;
	
	/** @var array<int, RedstoneWorldState> Redstone state for each loaded world ID. */
	private(set) array $worlds = [];
	/** @var array<int, bool> Runtime world overrides, keyed by world ID. */
	private array $worldOverrides = [];
	/** @var array<int, bool> Cached results from the configured world policy. */
	private array $configuredWorldEnabled = [];
	/** @var array<int, array<int, bool>> Runtime chunk overrides, grouped by world ID. */
	private array $chunkOverrides = [];
	/** @var array<int, int> Number of explicitly enabled chunks in each world. */
	private array $enabledChunkOverrideCounts = [];
	public function __construct(Server $server){
		self::$instance = $this;
		foreach($server->getWorldManager()->getWorlds() as $world){
			$this->load($world);
		}
	}

	public static function getInstance() : self{
		return self::$instance ?? throw new \LogicException('RedstoneManager has not been initialized');
	}

	public function tick() : void{
		Timings::$redstone->time(function() : void{
			foreach($this->worlds as $worldId => $world){
				if($this->isWorldEnabled($world->world) || ($this->enabledChunkOverrideCounts[$worldId] ?? 0) > 0){
					$world->tick();
				}
			}
		});
	}

	/** @api Runtime override; call from the main thread. */
	public function setWorldEnabled(World $world, bool $enabled) : void{
		$this->worldOverrides[$world->getId()] = $enabled;
	}

	/** @api Removes the runtime override and restores the configured world policy. */
	public function clearWorldOverride(World $world) : void{
		unset($this->worldOverrides[$world->getId()]);
	}

	/** @api Runtime override; chunk policy takes precedence over world policy. Call from the main thread. */
	public function setChunkEnabled(World $world, int $chunkX, int $chunkZ, bool $enabled) : void{
		$worldId = $world->getId();
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		$previous = $this->chunkOverrides[$worldId][$chunkHash] ?? null;
		if($previous === $enabled){
			return;
		}
		if($previous === true){
			--$this->enabledChunkOverrideCounts[$worldId];
		}
		$this->chunkOverrides[$worldId][$chunkHash] = $enabled;
		if($enabled){
			$this->enabledChunkOverrideCounts[$worldId] = ($this->enabledChunkOverrideCounts[$worldId] ?? 0) + 1;
		}
	}

	/** @api Removes the runtime chunk override and restores the effective world policy. */
	public function clearChunkOverride(World $world, int $chunkX, int $chunkZ) : void{
		$worldId = $world->getId();
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		if(($this->chunkOverrides[$worldId][$chunkHash] ?? null) === true){
			--$this->enabledChunkOverrideCounts[$worldId];
		}
		unset($this->chunkOverrides[$worldId][$chunkHash]);
		if(($this->chunkOverrides[$worldId] ?? []) === []){
			unset($this->chunkOverrides[$worldId], $this->enabledChunkOverrideCounts[$worldId]);
		}
	}

	/** @api Returns the configured or runtime-overridden world policy. */
	public function isWorldEnabled(World $world) : bool{
		$worldId = $world->getId();
		return $this->worldOverrides[$worldId] ?? ($this->configuredWorldEnabled[$worldId] ??= RedstoneConfig::isEnabledForWorld($world));
	}

	/** @api Does not load the chunk. */
	public function isChunkEnabled(World $world, int $chunkX, int $chunkZ) : bool{
		$worldId = $world->getId();
		return $this->chunkOverrides[$worldId][World::chunkHash($chunkX, $chunkZ)] ?? $this->worldOverrides[$worldId] ?? ($this->configuredWorldEnabled[$worldId] ??= RedstoneConfig::isEnabledForWorld($world));
	}

	/** @api Does not load the chunk. */
	public function isEnabledAt(Position $position) : bool{
		return $this->isChunkEnabled($position->getWorld(), ((int) $position->x) >> 4, ((int) $position->z) >> 4);
	}

	public function load(World $world) : void{
		if(isset($this->worlds[$world->getId()])){
			return;
		}
		$redstone_world = $this->worlds[$world->getId()] = new RedstoneWorldState($world);
		foreach($world->getLoadedChunks() as $chunk_hash => $_){
			World::getXZ($chunk_hash, $chunkX, $chunkZ);
			$redstone_world->loadChunk($chunkX, $chunkZ);
		}
		$world->addOnUnloadCallback(fn() => $this->unload($world));
	}

	public function unload(World $world) : void{
		$worldId = $world->getId();
		unset($this->worlds[$worldId], $this->worldOverrides[$worldId], $this->configuredWorldEnabled[$worldId], $this->chunkOverrides[$worldId], $this->enabledChunkOverrideCounts[$worldId]);
	}

	public function get(World $world) : RedstoneWorldState{
		if(!isset($this->worlds[$world->getId()])){
			$this->load($world);
		}
		return $this->worlds[$world->getId()];
	}

	public function getNullable(World $world) : ?RedstoneWorldState{
		return $this->worlds[$world->getId()] ?? null;
	}
}
