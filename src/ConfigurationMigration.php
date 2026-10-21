<?php

declare(strict_types=1);

namespace quark;

use quark\plugin\PluginGraylist;
use quark\utils\Config;
use quark\utils\Filesystem;
use quark\utils\Utils;
use Symfony\Component\Filesystem\Path;
use function array_key_exists;
use function array_keys;
use function array_replace;
use function array_replace_recursive;
use function basename;
use function bin2hex;
use function copy;
use function ctype_digit;
use function dirname;
use function disk_free_space;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function gmdate;
use function hash;
use function hash_file;
use function in_array;
use function is_array;
use function is_bool;
use function is_file;
use function is_int;
use function is_link;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function parse_ini_string;
use function preg_match;
use function random_bytes;
use function rename;
use function str_starts_with;
use function strlen;
use function unlink;
use function yaml_parse;
use const INI_SCANNER_TYPED;
use const JSON_THROW_ON_ERROR;

/**
 * The configuration stage runs while FilesystemMigration holds its exclusive lock.
 */
final class ConfigurationMigration{
	public function __construct(private FilesystemPaths $paths){}

	private function journalPath() : string{
		return Path::join($this->paths->root, ".quark-config-migration.json");
	}

	/** @return array<string, string> */
	private function sources() : array{
		return [
			"pocketmine.yml" => Path::join($this->paths->root, "pocketmine.yml"),
			"quark.yml" => $this->paths->quarkYml(),
			"server.properties" => $this->paths->serverProperties(),
			"plugin_list.yml" => $this->paths->pluginList(),
			"migration.ini" => Path::join($this->paths->config(), "migration.ini")
		];
	}

	public function hasLegacy() : bool{
		if(is_file($this->journalPath()) || is_link($this->journalPath())){
			return true;
		}
		foreach(Utils::stringifyKeys($this->sources()) as $name => $source){
			$rootSource = Path::join($this->paths->root, $name);
			if(file_exists($source) || is_link($source) || $name !== "migration.ini" && (file_exists($rootSource) || is_link($rootSource))){
				return true;
			}
		}
		return false;
	}

	/** @return list<string> */
	public function dryRun() : array{
		if(!$this->hasLegacy()){
			return [];
		}
		$lines = [];
		$bytes = 0;
		foreach(Utils::stringifyKeys($this->sources()) as $name => $source){
			$rootSource = Path::join($this->paths->root, $name);
			if($name !== "migration.ini" && (is_file($rootSource) || is_link($rootSource))){
				$source = $rootSource;
			}
			$this->safe($source);
			if(is_file($source)){
				$size = filesize($source);
				$bytes += $size === false ? 0 : $size;
				$lines[] = "SNAPSHOT $source";
			}
		}
		$free = disk_free_space($this->paths->root);
		$lines[] = "CONVERT to " . $this->paths->serverToml() . " and " . $this->paths->pluginsToml() . " ($bytes snapshot bytes, " . ($free === false ? "unknown" : (string) $free) . " bytes free)";
		foreach([$this->paths->serverToml(), $this->paths->pluginsToml()] as $destination){
			$this->safe($destination);
			if(file_exists($destination) || is_link($destination)){
				$lines[] = "COLLISION $destination";
			}
		}
		return $lines;
	}

	public function begin() : void{
		$journalPath = $this->journalPath();
		if(is_file($journalPath)){
			$this->readJournal();
			return;
		}
		if(!$this->hasLegacy()){
			return;
		}
		foreach([$this->paths->serverToml(), $this->paths->pluginsToml()] as $destination){
			$this->safe($destination);
			if(file_exists($destination) || is_link($destination)){
				throw new \RuntimeException("Configuration migration collision: $destination already exists");
			}
		}
		$backup = Path::join($this->paths->backups(), "config-" . gmdate("Ymd-His") . "-" . bin2hex(random_bytes(4)));
		$this->safe($backup);
		if(!@mkdir($backup, 0777, true)){
			throw new \RuntimeException("Cannot create configuration snapshot: $backup");
		}
		$originals = [];
		foreach(Utils::stringifyKeys($this->sources()) as $name => $source){
			$this->safe($source);
			$rootSource = Path::join($this->paths->root, $name);
			$this->safe($rootSource);
			if($name !== "pocketmine.yml" && $name !== "migration.ini" && is_file($rootSource)){
				$source = $rootSource;
			}
			if(!is_file($source)){
				continue;
			}
			$size = filesize($source);
			$free = disk_free_space($backup);
			if($size === false || $free === false || $size > $free){
				throw new \RuntimeException("Insufficient disk space to snapshot $source");
			}
			$target = Path::join($backup, $name);
			if(!@copy($source, $target) || hash_file("sha256", $source) !== hash_file("sha256", $target)){
				throw new \RuntimeException("Cannot verify configuration snapshot: $source");
			}
			$originals[$name] = ["path" => $source, "hash" => hash_file("sha256", $target)];
		}
		$this->writeJournal(["version" => 2, "backup" => $backup, "originals" => $originals]);
	}

	public function finish() : void{
		if(!is_file($this->journalPath())){
			return;
		}
		$journal = $this->readJournal();
		$backup = $journal["backup"];
		$originals = $journal["originals"];
		$read = function(string $name) use ($backup, $originals) : ?string{
			if(!isset($originals[$name])){
				return null;
			}
			$path = Path::join($backup, $name);
			$this->safe($path);
			$content = @file_get_contents($path);
			if($content === false || hash("sha256", $content) !== $originals[$name]["hash"]){
				throw new \RuntimeException("Configuration snapshot changed: $path");
			}
			return $content;
		};
		$settings = [];
		foreach(["pocketmine.yml", "quark.yml"] as $name){
			$content = $read($name);
			if($content === null){
				continue;
			}
			$value = @yaml_parse(Config::fixYAMLIndexes($content));
			if(!is_array($value)){
				throw new \RuntimeException("Invalid legacy configuration: $name");
			}
			$this->merge($settings, $value, $name);
		}
		$defaults = CoreConfig::parse(Filesystem::fileGetContents(Path::join(RESOURCE_PATH, "server.toml")));
		if(!is_array($defaults["server"] ?? null)){
			throw new \RuntimeException("Invalid bundled server.toml defaults");
		}
		$properties = $read("server.properties");
		if($properties !== null){
			$parsed = Config::parseProperties($properties);
			foreach(array_keys($parsed) as $key){
				$value = $parsed[$key];
				$expected = $defaults["server"][$key] ?? null;
				if(is_bool($expected)){
					if(!is_bool($value) && !in_array($value, [0, 1, "0", "1"], true)){
						throw new \RuntimeException("Invalid legacy setting server.$key: expected boolean");
					}
					$value = (bool) $value;
				}elseif(is_int($expected)){
					if(!is_int($value) && !(is_string($value) && ctype_digit($value))){
						throw new \RuntimeException("Invalid legacy setting server.$key: expected integer");
					}
					$value = (int) $value;
				}elseif(is_string($expected) || in_array($key, ["server-ip", "server-ipv6"], true)){
					$value = (string) $value;
				}
				$parsed[$key] = $value;
			}
			$defaults["server"] = array_replace($defaults["server"], $parsed);
		}
		unset($defaults["migration"]);
		foreach(Utils::stringifyKeys($settings) as $key => $value){
			if($key === "server" || $key === "migration" || $key === "__quark_nulls"){
				throw new \RuntimeException("Legacy configuration key $key conflicts with a reserved TOML section");
			}
			$defaults[$key] = is_array($value) && isset($defaults[$key]) && is_array($defaults[$key]) ? array_replace_recursive($defaults[$key], $value) : $value;
		}
		$migrationSetting = false;
		if(($content = $read("migration.ini")) !== null){
			$settings = parse_ini_string($content, true, INI_SCANNER_TYPED);
			if(!is_array($settings) || !is_bool($settings["migration"]["auto_on_startup"] ?? null)){
				throw new \RuntimeException("Invalid legacy setting migration.auto_on_startup");
			}
			$migrationSetting = $settings["migration"]["auto_on_startup"];
		}
		$defaults["migration"] = ["auto_on_startup" => $migrationSetting];
		CoreConfig::validateServer($defaults);
		$plugins = ["mode" => "blacklist", "plugins" => []];
		if(($content = $read("plugin_list.yml")) !== null){
			$value = @yaml_parse(Config::fixYAMLIndexes($content));
			if(!is_array($value)){
				throw new \RuntimeException("Invalid legacy configuration: plugin_list.yml");
			}
			$plugins = array_replace($plugins, $value);
		}
		CoreConfig::validatePlugins($plugins);
		$plugins["plugins"] = PluginGraylist::fromArray($plugins)->getPlugins();
		$this->publish($this->paths->serverToml(), CoreConfig::encode($defaults), fn(array $data) => CoreConfig::validateServer($data));
		$this->publish($this->paths->pluginsToml(), CoreConfig::encode($plugins), fn(array $data) => CoreConfig::validatePlugins($data));
		foreach(Utils::stringifyKeys($originals) as $name => $original){
			foreach([$original["path"], $this->sources()[$name]] as $source){
				$this->safe($source);
				if(is_file($source)){
					if(hash_file("sha256", $source) !== $original["hash"]){
						throw new \RuntimeException("Legacy configuration changed during migration: $source");
					}
					if(!@unlink($source)){
						throw new \RuntimeException("Cannot retire legacy configuration: $source");
					}
				}
			}
		}
		if(!@unlink($this->journalPath())){
			throw new \RuntimeException("Cannot remove configuration migration journal");
		}
	}

	/**
	 * @phpstan-param array<mixed, mixed> $into
	 * @phpstan-param array<mixed, mixed> $from
	 */
	private function merge(array &$into, array $from, string $source, string $prefix = "") : void{
		foreach(Utils::promoteKeys($from) as $key => $value){
			$path = $prefix === "" ? (string) $key : "$prefix.$key";
			if(!array_key_exists($key, $into)){
				$into[$key] = $value;
			}elseif(is_array($into[$key]) && is_array($value)){
				$this->merge($into[$key], $value, $source, $path);
			}elseif($into[$key] !== $value){
				throw new \RuntimeException("Conflicting legacy setting $path in $source");
			}
		}
	}

	/** @param callable(array<string, mixed>): void $validate */
	private function publish(string $path, string $content, callable $validate) : void{
		$this->safe($path);
		$validate(CoreConfig::parse($content));
		if(is_file($path)){
			$existing = file_get_contents($path);
			if($existing !== $content){
				throw new \RuntimeException("Configuration migration collision: $path already exists with different content");
			}
			return;
		}
		$tmp = $path . ".migration-tmp";
		$this->safe($tmp);
		if(@file_put_contents($tmp, $content) !== strlen($content) || !@rename($tmp, $path)){
			throw new \RuntimeException("Cannot publish migrated configuration: $path");
		}
		$validate(CoreConfig::parse(Filesystem::fileGetContents($path)));
	}

	/** @return array{version: int, backup: string, originals: array<string, array{path: string, hash: string}>} */
	private function readJournal() : array{
		$path = $this->journalPath();
		$this->safe($path);
		$data = json_decode((string) @file_get_contents($path), true);
		if(!is_array($data) || ($data["version"] ?? null) !== 2 || !is_string($data["backup"] ?? null) || !is_array($data["originals"] ?? null)){
			throw new \RuntimeException("Invalid configuration migration journal: $path");
		}
		if(dirname($data["backup"]) !== $this->paths->backups() || !str_starts_with(basename($data["backup"]), "config-")){
			throw new \RuntimeException("Invalid configuration snapshot path in $path");
		}
		$sources = $this->sources();
		foreach(Utils::promoteKeys($data["originals"]) as $name => $original){
			if(!is_string($name) || !isset($sources[$name]) || !is_array($original) || !is_string($original["path"] ?? null) || !is_string($original["hash"] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $original["hash"]) !== 1 || !in_array($original["path"], [$sources[$name], Path::join($this->paths->root, $name)], true)){
				throw new \RuntimeException("Invalid configuration snapshot entry in $path");
			}
		}
		$this->safe($data["backup"]);
		/** @var array{version: int, backup: string, originals: array<string, array{path: string, hash: string}>} $data */
		return $data;
	}

	/** @param array<string, mixed> $data */
	private function writeJournal(array $data) : void{
		$path = $this->journalPath();
		$tmp = $path . ".tmp";
		$this->safe($tmp);
		$content = json_encode($data, JSON_THROW_ON_ERROR);
		if(@file_put_contents($tmp, $content) !== strlen($content) || !@rename($tmp, $path)){
			throw new \RuntimeException("Cannot write configuration migration journal: $path");
		}
	}

	private function safe(string $path) : void{
		for($part = $path; $part !== dirname($part); $part = dirname($part)){
			if(is_link($part)){
				throw new \RuntimeException("Configuration migration refuses symbolic link: $part");
			}
		}
	}
}
