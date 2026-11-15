<?php

declare(strict_types=1);

namespace quark;

use quark\plugin\PluginGraylist;
use quark\utils\Config;
use quark\utils\Filesystem;
use quark\utils\Utils;
use Symfony\Component\Filesystem\Path;
use function array_is_list;
use function array_key_exists;
use function array_replace;
use function array_replace_recursive;
use function basename;
use function bin2hex;
use function clearstatcache;
use function count;
use function ctype_digit;
use function dirname;
use function disk_free_space;
use function file_exists;
use function file_get_contents;
use function filesize;
use function gmdate;
use function hash;
use function hash_file;
use function in_array;
use function intdiv;
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
use function rmdir;
use function str_starts_with;
use function strlen;
use function strtoupper;
use function trim;
use function unlink;
use function yaml_parse;
use const INI_SCANNER_TYPED;
use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;

/**
 * The configuration stage runs while FilesystemMigration holds its exclusive lock.
 */
final class ConfigurationMigration{
	/** @var array{server: string, plugins: string}|null */
	private ?array $prepared = null;

	public function __construct(private FilesystemPaths $paths){}

	public static function createServer(string $path) : void{
		Filesystem::atomicWrite($path, Filesystem::fileGetContents(Path::join(RESOURCE_PATH, "server.toml")));
	}

	public function upgradeServer() : void{
		$path = $this->paths->serverToml();
		$this->safe($path);
		if(!file_exists($path)){
			return;
		}
		$data = CoreConfig::load($path);
		if(($data["config-version"] ?? null) === CoreConfig::SERVER_VERSION){
			return;
		}
		$original = Filesystem::fileGetContents($path);
		$upgraded = self::upgradeSettings(CoreConfig::parse($original));
		Filesystem::atomicWrite($path, CoreConfig::update($original, $upgraded), $original);
		CoreConfig::forget($path);
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<array-key, mixed>
	 */
	private static function table(array $data, string $section) : array{
		if(!array_key_exists($section, $data)){
			return [];
		}
		$table = $data[$section];
		if(!is_array($table) || $table !== [] && array_is_list($table)){
			throw new \InvalidArgumentException("Invalid $section: expected table");
		}
		return $table;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private static function upgradeSettings(array $data, bool $fromToml = true) : array{
		if(array_key_exists("config-version", $data)){
			if($data["config-version"] === CoreConfig::SERVER_VERSION){
				CoreConfig::validateServer($data);
				return $data;
			}
			if($data["config-version"] !== 0){
				throw new \InvalidArgumentException("Unsupported config-version in config/server.toml");
			}
		}
		$server = self::table($data, "server");
		$settings = self::table($data, "settings");
		if(array_key_exists("language", $settings)){
			if(!array_key_exists("language", $server)){
				$server["language"] = $settings["language"];
			}
			unset($settings["language"]);
			$data["settings"] = $settings;
		}
		if(array_key_exists("memory-limit", $server)){
			$limit = $server["memory-limit"];
			$memory = self::table($data, "memory");
			if(!array_key_exists("main-hard-limit", $memory)){
				if($limit === -1 || $limit === 0 || $limit === "-1" || $limit === "0"){
					$memory["main-hard-limit"] = 0;
				}elseif(is_string($limit) && preg_match('/^([0-9]+)([KMG])$/iD', $limit, $match) === 1){
					$amount = (int) $match[1];
					$memory["main-hard-limit"] = match(strtoupper($match[2])){
						"K" => intdiv($amount, 1024),
						"G" => $amount <= intdiv(PHP_INT_MAX, 1024) ? $amount * 1024 : throw new \InvalidArgumentException("Invalid server.memory-limit: too large"),
						default => $amount
					};
				}else{
					throw new \InvalidArgumentException("Invalid server.memory-limit: expected a PHP memory size");
				}
				$data["memory"] = $memory;
			}
			unset($server["memory-limit"]);
		}
		$data["server"] = $server;
		$chunkTicking = self::table($data, "chunk-ticking");
		if(array_key_exists("per-tick", $chunkTicking)){
			$perTick = $chunkTicking["per-tick"];
			if(!is_int($perTick)){
				throw new \InvalidArgumentException("Invalid chunk-ticking.per-tick: expected integer");
			}
			if($perTick <= 0){
				$chunkTicking["tick-radius"] = 0;
			}
			unset($chunkTicking["per-tick"]);
			$data["chunk-ticking"] = $chunkTicking;
		}
		$debug = $data["debug"] ?? null;
		if(is_array($debug) && array_key_exists("level", $debug)){
			$level = $debug["level"];
			if(!is_int($level) || $level < 0){
				throw new \InvalidArgumentException("Invalid debug.level: expected a nonnegative integer");
			}
			if($level > 1){
				$data["logging"] = self::table($data, "logging") + ["console-level" => "debug", "file-level" => "debug"];
			}
			unset($debug["level"]);
			if($debug === []){
				unset($data["debug"]);
			}else{
				$data["debug"] = $debug;
			}
		}
		$updater = self::table($data, "auto-updater");
		if(array_key_exists("suggest-channels", $updater)){
			unset($updater["suggest-channels"]);
			$data["auto-updater"] = $updater;
		}
		// Older YAML samples represented empty lists and tables as null.
		foreach(["aliases", "worlds"] as $key){
			if(array_key_exists($key, $data) && $data[$key] === null){
				$data[$key] = [];
			}
		}
		foreach(["network" => "trusted-proxy-keys", "chunk-ticking" => "disable-block-ticking"] as $section => $key){
			if(is_array($data[$section] ?? null) && array_key_exists($key, $data[$section]) && $data[$section][$key] === null){
				$data[$section][$key] = [];
			}
		}
		$transport = self::table($data, "transport");
		$nethernet = $transport["nethernet"] ?? null;
		if(is_array($nethernet)){
			$nethernet["ice-servers"] ??= [];
			if(($nethernet["port-range"] ?? null) === null){
				unset($nethernet["port-range"]);
			}
			$transport["nethernet"] = $nethernet;
			$data["transport"] = $transport;
		}
		$worlds = $data["worlds"] ?? null;
		if(is_array($worlds)){
			foreach(Utils::promoteKeys($worlds) as $name => $world){
				if($world === null){
					$worlds[$name] = [];
				}
			}
			$data["worlds"] = $worlds;
		}
		$data["config-version"] = CoreConfig::SERVER_VERSION;
		CoreConfig::validateServer($data);
		// Sparse TOML used runtime fallbacks that differed from the shipped template.
		$previousDefaults = $fromToml ? [
			"tnt-limits" => ["enabled" => true],
			"chunk-generation" => ["population-queue-size" => 2],
			"chunk-ticking" => ["tick-radius" => 4],
			"network" => ["async-compression" => true],
			"memory" => ["continuous-trigger-rate" => 40],
		] : [];
		$data = array_replace_recursive(CoreConfig::defaults(), $previousDefaults, $data);
		CoreConfig::validateServer($data);
		return $data;
	}

	/**
	 * Converts a YAML configuration document to TOML.
	 *
	 * @phpstan-param (\Closure(array<string, mixed>) : string)|null $render writes the parsed values as TOML, encoding them as they are by default
	 * @throws \RuntimeException if the YAML is malformed or can't be expressed as TOML
	 */
	public static function yamlToToml(string $yaml, string $origin, ?\Closure $render = null) : string{
		$value = self::parseLegacyYaml($yaml, $origin);
		try{
			$toml = $render !== null ? $render($value) : CoreConfig::encode($value);
			CoreConfig::parse($toml);
		}catch(\InvalidArgumentException|\TomlException $e){
			throw new \RuntimeException("Cannot convert $origin to TOML: " . $e->getMessage(), 0, $e);
		}
		return $toml;
	}

	/**
	 * Replaces a YAML configuration file with its TOML equivalent. The original is snapshotted in $backups first,
	 * since its comments don't survive the conversion.
	 *
	 * @phpstan-param (\Closure(array<string, mixed>) : string)|null $render see yamlToToml()
	 * @return bool whether a file was converted
	 */
	public static function convertYamlFile(string $yamlFile, string $tomlFile, string $backups, ?\Closure $render = null) : bool{
		if(!is_file($yamlFile) || file_exists($tomlFile) || is_link($tomlFile)){
			return false;
		}
		if(is_link($yamlFile)){
			throw new \RuntimeException("Configuration migration refuses symbolic link: $yamlFile");
		}
		$content = Filesystem::fileGetContents($yamlFile);
		$toml = self::yamlToToml($content, $yamlFile, $render);
		$backup = Path::join($backups, "yaml-" . gmdate("Ymd-His") . "-" . bin2hex(random_bytes(4)));
		if(!@mkdir($backup, 0700, true)){
			throw new \RuntimeException("Cannot snapshot $yamlFile to $backup");
		}
		Filesystem::atomicWrite(Path::join($backup, basename($yamlFile)), $content);
		if(!self::matchesSource($yamlFile, $content)){
			throw new \RuntimeException("Legacy configuration changed during migration: $yamlFile");
		}
		Filesystem::atomicWrite($tomlFile, $toml);
		if(!self::matchesSource($yamlFile, $content) || !@unlink($yamlFile)){
			throw new \RuntimeException("Cannot retire $yamlFile after converting it to $tomlFile");
		}
		return true;
	}

	private static function matchesSource(string $path, string $content) : bool{
		clearstatcache(true, $path);
		return !is_link($path) && is_file($path) && Filesystem::fileGetContents($path) === $content;
	}

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
		$lines = [];
		$path = $this->paths->serverToml();
		$this->safe($path);
		if(is_file($path)){
			$data = CoreConfig::load($path);
			if(($data["config-version"] ?? null) !== CoreConfig::SERVER_VERSION){
				self::upgradeSettings($data);
				$lines[] = "UPGRADE $path to config-version " . CoreConfig::SERVER_VERSION;
			}
		}
		if(!$this->hasLegacy()){
			return $lines;
		}
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
		if(!$this->hasLegacy()){
			return;
		}
		$journal = is_file($journalPath) ? $this->readJournal() : null;
		foreach([$this->paths->serverToml(), $this->paths->pluginsToml()] as $destination){
			$this->safe($destination);
			if(file_exists($destination)){
				if($journal === null){
					throw new \RuntimeException("Configuration migration collision: $destination already exists");
				}
				$this->prepared = $this->renderLegacy($this->readSnapshot($journal["backup"], $journal["originals"]));
				return;
			}
		}
		[$originals, $contents] = $this->readLegacySources();
		if($journal !== null){
			$changed = false;
			foreach(Utils::stringifyKeys($journal["originals"]) as $name => $original){
				if(!isset($originals[$name])){
					throw new \RuntimeException("Legacy configuration missing before publication: $name");
				}
				$changed = $changed || $originals[$name]["hash"] !== $original["hash"];
			}
			if(!$changed && count($originals) === count($journal["originals"])){
				$this->prepared = $this->renderLegacy($this->readSnapshot($journal["backup"], $journal["originals"]));
				return;
			}
		}

		$this->prepared = $this->renderLegacy($contents);
		$backup = Path::join($this->paths->backups(), "config-" . gmdate("Ymd-His") . "-" . bin2hex(random_bytes(4)));
		$this->safe($backup);
		if(!@mkdir($backup, 0700, true)){
			throw new \RuntimeException("Cannot create configuration snapshot: $backup");
		}
		try{
			foreach(Utils::stringifyKeys($contents) as $name => $content){
				Filesystem::atomicWrite(Path::join($backup, $name), $content);
			}
			$this->writeJournal(["version" => 2, "backup" => $backup, "originals" => $originals]);
		}catch(\Throwable $e){
			foreach(Utils::stringifyKeys($contents) as $name => $content){
				@unlink(Path::join($backup, $name));
			}
			@rmdir($backup);
			throw $e;
		}
	}

	/** @return array{array<string, array{path: string, hash: string}>, array<string, string>} */
	private function readLegacySources() : array{
		$originals = [];
		$contents = [];
		foreach(Utils::stringifyKeys($this->sources()) as $name => $source){
			$this->safe($source);
			$rootSource = Path::join($this->paths->root, $name);
			$this->safe($rootSource);
			if($name !== "pocketmine.yml" && $name !== "migration.ini" && is_file($rootSource)){
				$source = $rootSource;
			}
			if(!file_exists($source)){
				continue;
			}
			if(!is_file($source)){
				throw new \RuntimeException("Legacy configuration is not a file: $source");
			}
			$content = Filesystem::fileGetContents($source);
			$contents[$name] = $content;
			$originals[$name] = ["path" => $source, "hash" => hash("sha256", $content)];
		}
		return [$originals, $contents];
	}

	/**
	 * @param array<string, array{path: string, hash: string}> $originals
	 * @return array<string, string>
	 */
	private function readSnapshot(string $backup, array $originals) : array{
		$contents = [];
		foreach(Utils::stringifyKeys($originals) as $name => $original){
			$path = Path::join($backup, $name);
			$this->safe($path);
			$content = @file_get_contents($path);
			if($content === false || hash("sha256", $content) !== $original["hash"]){
				throw new \RuntimeException("Configuration snapshot changed: $path");
			}
			$contents[$name] = $content;
		}
		return $contents;
	}

	/**
	 * @param array<string, string> $contents
	 * @return array{server: string, plugins: string}
	 */
	private function renderLegacy(array $contents) : array{
		$settings = [];
		foreach(["pocketmine.yml", "quark.yml"] as $name){
			$content = $contents[$name] ?? null;
			if($content === null){
				continue;
			}
			$this->merge($settings, self::parseLegacyYaml($content, $name), $name);
		}
		foreach(["server", "migration", "config-version", "__quark_nulls"] as $key){
			if(array_key_exists($key, $settings)){
				throw new \RuntimeException("Legacy configuration key $key conflicts with a reserved TOML section");
			}
		}
		$serverDefaults = self::table(CoreConfig::defaults(), "server");
		$strings = [];
		foreach(Utils::promoteKeys($serverDefaults) as $key => $value){
			if(is_string($key) && is_string($value)){
				$strings[$key] = true;
			}
		}
		$properties = $contents["server.properties"] ?? null;
		if($properties !== null){
			$parsed = Config::parseProperties($properties, $strings, true);
			foreach(Utils::promoteKeys($parsed) as $key => $value){
				$expected = $serverDefaults[$key] ?? null;
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
				}
				$parsed[$key] = $value;
			}
			$settings["server"] = $parsed;
		}
		$migrationSetting = false;
		if(($content = $contents["migration.ini"] ?? null) !== null){
			$ini = parse_ini_string($content, true, INI_SCANNER_TYPED);
			if(!is_array($ini) || !is_bool($ini["migration"]["auto_on_startup"] ?? null)){
				throw new \RuntimeException("Invalid legacy setting migration.auto_on_startup");
			}
			$migrationSetting = $ini["migration"]["auto_on_startup"];
		}
		$settings["migration"] = ["auto_on_startup" => $migrationSetting];
		$settings = self::upgradeSettings($settings, fromToml: false);
		$plugins = ["mode" => "blacklist", "plugins" => []];
		if(($content = $contents["plugin_list.yml"] ?? null) !== null){
			$value = self::parseLegacyYaml($content, "plugin_list.yml");
			$value["plugins"] ??= [];
			$plugins = array_replace($plugins, $value);
		}
		$plugins["plugins"] = PluginGraylist::fromArray($plugins)->getPlugins();
		$serverTemplate = Filesystem::fileGetContents(Path::join(RESOURCE_PATH, "server.toml"));
		$pluginsTemplate = Filesystem::fileGetContents(Path::join(RESOURCE_PATH, "plugins.toml"));
		return [
			"server" => CoreConfig::update($serverTemplate, $settings),
			"plugins" => CoreConfig::update($pluginsTemplate, $plugins),
		];
	}

	public function finish() : void{
		if(!is_file($this->journalPath())){
			return;
		}
		$journal = $this->readJournal();
		$prepared = $this->prepared ?? $this->renderLegacy($this->readSnapshot($journal["backup"], $journal["originals"]));
		$sources = $this->sources();
		$retire = [];
		foreach(Utils::stringifyKeys($journal["originals"]) as $name => $original){
			foreach([$original["path"], $sources[$name]] as $source){
				$this->safe($source);
				if(file_exists($source) && (!is_file($source) || hash_file("sha256", $source) !== $original["hash"])){
					throw new \RuntimeException("Legacy configuration changed during migration: $source");
				}
				if(is_file($source)){
					$retire[$source] = $original["hash"];
				}
			}
		}
		$this->publish($this->paths->serverToml(), $prepared["server"], static fn(array $data) => self::upgradeSettings($data, fromToml: false));
		$this->publish($this->paths->pluginsToml(), $prepared["plugins"], static fn(array $data) => $data);
		foreach(Utils::stringifyKeys($retire) as $source => $hash){
			$this->safe($source);
			if(!is_file($source) || hash_file("sha256", $source) !== $hash || !@unlink($source)){
				throw new \RuntimeException("Cannot retire legacy configuration: $source");
			}
		}
		if(!@unlink($this->journalPath())){
			throw new \RuntimeException("Cannot remove configuration migration journal");
		}
		$this->prepared = null;
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

	/** @return array<string, mixed> */
	private static function parseLegacyYaml(string $content, string $name) : array{
		if(trim($content) === ""){
			return [];
		}
		$documents = @yaml_parse(Config::fixYAMLIndexes($content), -1);
		if(!is_array($documents) || count($documents) !== 1 || !is_array($value = $documents[0])){
			throw new \RuntimeException("Invalid legacy configuration: $name");
		}
		// YAML aliases can create cycles or expand into much larger TOML documents.
		$budget = 64 * 1024 * 1024;
		self::checkYamlTree($value, $name, $budget);
		/** @var array<string, mixed> $value */
		return $value;
	}

	/** @param array<array-key, mixed> $node */
	private static function checkYamlTree(array $node, string $name, int &$budget, int $depth = 0) : void{
		$budget -= 64 * count($node);
		if($budget < 0 || $depth >= 128){
			throw new \RuntimeException("YAML configuration is too deeply nested or expands beyond the migration limit: $name");
		}
		foreach(Utils::promoteKeys($node) as $key => $value){
			$budget -= (is_string($key) ? strlen($key) : 0) + (is_string($value) ? strlen($value) : 0);
			if($budget < 0){
				throw new \RuntimeException("YAML configuration expands beyond the migration limit: $name");
			}
			if(is_array($value)){
				self::checkYamlTree($value, $name, $budget, $depth + 1);
			}
		}
	}

	/** @param callable(array<string, mixed>): array<string, mixed> $normalize */
	private function publish(string $path, string $content, callable $normalize) : void{
		$this->safe($path);
		$expected = $normalize(CoreConfig::parse($content));
		if(is_file($path)){
			$existing = Filesystem::fileGetContents($path);
			if($existing !== $content && !CoreConfig::equivalent($normalize(CoreConfig::parse($existing)), $expected)){
				throw new \RuntimeException("Configuration migration collision: $path already exists with different content");
			}
			return;
		}
		Filesystem::atomicWrite($path, $content);
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
		$this->safe($path);
		$original = is_file($path) ? Filesystem::fileGetContents($path) : null;
		Filesystem::atomicWrite($path, json_encode($data, JSON_THROW_ON_ERROR), $original);
	}

	private function safe(string $path) : void{
		for($part = $path; $part !== dirname($part); $part = dirname($part)){
			if(is_link($part)){
				throw new \RuntimeException("Configuration migration refuses symbolic link: $part");
			}
		}
	}
}
