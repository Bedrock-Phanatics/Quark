<?php

declare(strict_types=1);

namespace quark;

use Symfony\Component\Filesystem\Path;

/**
 * Paths owned by one server installation.
 */
final class FilesystemPaths{
	/** @var array<string, string> */
	private array $paths;

	public function __construct(public readonly string $root, public readonly string $plugins){
		$config = Path::join($root, "config");
		$data = Path::join($root, "data");
		$diagnostics = Path::join($root, "diagnostics");
		$this->paths = [
			"config" => $config,
			"serverToml" => Path::join($config, "server.toml"),
			"pluginsToml" => Path::join($config, "plugins.toml"),
			"serverProperties" => Path::join($config, "server.properties"),
			"quarkYml" => Path::join($config, "quark.yml"),
			"pluginList" => Path::join($config, "plugin_list.yml"),
			"players" => Path::join($data, "players"),
			"worlds" => Path::join($data, "worlds"),
			"pluginData" => Path::join($data, "plugins"),
			"accessDatabase" => Path::join($data, "access.sqlite"),
			"resourcePacks" => Path::join($root, "resource-packs"),
			"logs" => Path::join($root, "logs"),
			"crashes" => Path::join($diagnostics, "crashes"),
			"profiles" => Path::join($diagnostics, "profiles"),
			"backups" => Path::join($root, "backups"),
			"runtime" => Path::join($root, "runtime"),
			"runtimeCache" => Path::join($root, "runtime", "cache"),
			"runtimeTmp" => Path::join($root, "runtime", "tmp"),
			"serverLock" => Path::join($root, "runtime", "server.lock"),
			"layoutFile" => Path::join($root, ".quark-layout.json"),
			"migrationJournal" => Path::join($root, ".quark-migration.json"),
			"migrationLock" => Path::join($root, ".quark-migration.lock")
		];
	}

	public function config() : string{ return $this->paths["config"]; }
	public function serverToml() : string{ return $this->paths["serverToml"]; }
	public function pluginsToml() : string{ return $this->paths["pluginsToml"]; }
	public function serverProperties() : string{ return $this->paths["serverProperties"]; }
	public function quarkYml() : string{ return $this->paths["quarkYml"]; }
	public function pluginList() : string{ return $this->paths["pluginList"]; }
	public function players() : string{ return $this->paths["players"]; }
	public function worlds() : string{ return $this->paths["worlds"]; }
	public function pluginData() : string{ return $this->paths["pluginData"]; }
	public function accessDatabase() : string{ return $this->paths["accessDatabase"]; }
	public function resourcePacks() : string{ return $this->paths["resourcePacks"]; }
	public function logs() : string{ return $this->paths["logs"]; }
	public function crashes() : string{ return $this->paths["crashes"]; }
	public function profiles() : string{ return $this->paths["profiles"]; }
	public function backups() : string{ return $this->paths["backups"]; }
	public function runtime() : string{ return $this->paths["runtime"]; }
	public function runtimeCache() : string{ return $this->paths["runtimeCache"]; }
	public function runtimeTmp() : string{ return $this->paths["runtimeTmp"]; }
	public function serverLock() : string{ return $this->paths["serverLock"]; }
	public function layoutFile() : string{ return $this->paths["layoutFile"]; }
	public function migrationJournal() : string{ return $this->paths["migrationJournal"]; }
	public function migrationLock() : string{ return $this->paths["migrationLock"]; }
}
