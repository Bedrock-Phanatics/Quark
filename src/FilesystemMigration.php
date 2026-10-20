<?php

declare(strict_types=1);

namespace quark;

use quark\utils\Utils;
use Symfony\Component\Filesystem\Path;
use function array_push;
use function assert;
use function basename;
use function chmod;
use function copy;
use function dirname;
use function disk_free_space;
use function fclose;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function fileperms;
use function filesize;
use function flock;
use function fopen;
use function hash_file;
use function hash_final;
use function hash_init;
use function hash_update;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function rename;
use function rmdir;
use function sort;
use function touch;
use function unlink;
use function yaml_parse_file;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;
use const SORT_STRING;

/**
 * Runs before anything opens server storage.
 */
final class FilesystemMigration{
	public const VERSION = 3;

	public function __construct(private FilesystemPaths $paths){}

	/** @return array<string, string> */
	private function moves() : array{
		$root = $this->paths->root;
		$config = $this->paths->config();
		return [
			Path::join($root, "server.properties") => $this->paths->serverProperties(),
			Path::join($root, "quark.yml") => $this->paths->quarkYml(),
			Path::join($root, "plugin_list.yml") => $this->paths->pluginList(),
			Path::join($root, "ops.txt") => Path::join($config, "ops.txt"),
			Path::join($root, "white-list.txt") => Path::join($config, "white-list.txt"),
			Path::join($root, "banned.txt") => Path::join($config, "banned.txt"),
			Path::join($root, "banned-players.txt") => Path::join($config, "banned-players.txt"),
			Path::join($root, "banned-ips.txt") => Path::join($config, "banned-ips.txt"),
			Path::join($root, "players") => $this->paths->players(),
			Path::join($root, "worlds") => $this->paths->worlds(),
			Path::join($root, "plugin_data") => $this->paths->pluginData(),
			Path::join($root, "resource_packs") => $this->paths->resourcePacks(),
			Path::join($root, "server.log") => Path::join($this->paths->logs(), "server.log"),
			Path::join($root, "log_archive") => Path::join($this->paths->logs(), "archive"),
			Path::join($root, "crashdumps") => $this->paths->crashes(),
			Path::join($root, "pulse") => $this->paths->profiles(),
			Path::join($root, "timings") => Path::join($this->paths->profiles(), "timings"),
			Path::join($root, "memory_dumps") => Path::join($this->paths->profiles(), "memory_dumps")
		];
	}

	private function exists(string $path) : bool{
		return file_exists($path) || is_link($path);
	}

	private function assertSafe(string $path) : void{
		for($part = $path; $part !== dirname($part); $part = dirname($part)){
			if(is_link($part)){
				throw new \RuntimeException("Migration refuses symbolic link: $part");
			}
		}
	}

	/** @return array{state: string, moves: list<string>} */
	public function diagnose() : array{
		$this->assertSafe($this->paths->root);
		$legacy = [];
		$modern = false;
		foreach(Utils::stringifyKeys($this->moves()) as $source => $destination){
			if($this->exists($source)){
				$this->assertSafe($source);
				$legacy[] = "$source -> $destination";
			}
			if($this->exists($destination)){
				$this->assertSafe($destination);
				$modern = true;
			}
		}
		foreach([$this->paths->config(), $this->paths->pluginData(), $this->paths->resourcePacks(), $this->paths->logs(), $this->paths->crashes(), $this->paths->profiles()] as $path){
			$modern = $modern || $this->exists($path);
		}
		if($this->exists($this->paths->migrationJournal()) || $this->exists(Path::join($this->paths->root, ".quark-config-migration.json")) || $this->exists(Path::join($this->paths->root, ".quark-access-migration.json"))){
			return ["state" => "partial", "moves" => $legacy];
		}
		$configLegacy = (new ConfigurationMigration($this->paths))->hasLegacy();
		$accessLegacy = (new AccessMigration($this->paths))->hasLegacy();
		$this->assertSafe($this->paths->accessDatabase());
		if($this->exists($this->paths->layoutFile())){
			$version = $this->readJson($this->paths->layoutFile())["version"] ?? null;
			if(!in_array($version, [1, 2, self::VERSION], true)){
				throw new \RuntimeException("Unsupported filesystem layout version in " . $this->paths->layoutFile());
			}
			foreach($this->requiredDirectories() as $path){
				if(!is_dir($path) || is_link($path)){
					throw new \RuntimeException("Versioned filesystem is incomplete: $path");
				}
			}
			if($version === self::VERSION && !$this->exists($this->paths->accessDatabase())){
				throw new \RuntimeException("Versioned filesystem is incomplete: " . $this->paths->accessDatabase());
			}
			return ["state" => $version === self::VERSION && $legacy === [] && !$configLegacy && !$accessLegacy ? "modern" : "mixed", "moves" => $legacy];
		}
		if($legacy === [] && is_dir($this->paths->plugins)){
			foreach(new \FilesystemIterator($this->paths->plugins, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $child){
				assert($child instanceof \SplFileInfo);
				if($child->isDir() || $child->isLink()){
					return ["state" => "unversioned", "moves" => []];
				}
			}
		}
		return ["state" => $legacy !== [] || $configLegacy || $accessLegacy ? ($modern ? "mixed" : "legacy") : ($modern ? "unversioned" : "empty"), "moves" => $legacy];
	}

	public function prepare(bool $allowMigration = false, bool $dryRun = false) : string{
		$this->assertSafe($this->paths->root);
		if($dryRun){
			$lines = [$this->diagnose()["state"]];
			$accessMoves = false;
			foreach(Utils::stringifyKeys($this->moves()) as $source => $destination){
				if(!$this->exists($source)){
					continue;
				}
				$this->assertTreeSafe($source);
				$accessMoves = $accessMoves || in_array(basename($source), ["ops.txt", "white-list.txt", "banned.txt", "banned-players.txt", "banned-ips.txt"], true);
				$parent = dirname($destination);
				while(!is_dir($parent) && $parent !== dirname($parent)){
					$parent = dirname($parent);
				}
				$bytes = $this->size($source);
				$available = disk_free_space($parent);
				$lines[] = ($this->exists($destination) ? "COLLISION " : "MOVE ") . "$source -> $destination ($bytes bytes, " . ($available === false ? "unknown" : (string) $available) . " bytes free at $parent)";
			}
			if($accessMoves){
				$lines[] = "SNAPSHOT legacy access files and IMPORT to " . $this->paths->accessDatabase();
			}
			if($this->exists(Path::join($this->paths->root, "plugin_data"))){
				$lines[] = "NOTICE plugins using hardcoded plugin_data/ paths must use getDataFolder(); no compatibility symlink is created";
			}
			array_push($lines, ...(new ConfigurationMigration($this->paths))->dryRun());
			array_push($lines, ...(new AccessMigration($this->paths))->dryRun());
			return implode("\n", $lines);
		}
		$lock = @fopen($this->paths->migrationLock(), "c+b");
		if($lock === false || !flock($lock, LOCK_EX | LOCK_NB)){
			throw new \RuntimeException("Another filesystem migration is running; check " . $this->paths->migrationLock());
		}
		try{
			$state = $this->diagnose()["state"];
			if($state === "modern"){
				return $state;
			}
			if(!$allowMigration && $state !== "empty"){
				throw new \RuntimeException("Filesystem layout is $state. Set [migration] auto_on_startup = true in config/server.toml or run --migrate-legacy; use --migration-dry-run to inspect first.");
			}
			if($state === "unversioned"){
				throw new \RuntimeException("Modern paths exist without a layout marker. Inspect this partial installation before retrying.");
			}
			$configMigration = new ConfigurationMigration($this->paths);
			if($state !== "empty"){
				$configMigration->begin();
				$this->migrate();
			}else{
				$this->writeJson($this->paths->migrationJournal(), ["version" => self::VERSION, "completed" => [], "copying" => null]);
			}
			$this->createDirectories();
			$configMigration->finish();
			(new AccessMigration($this->paths))->run();
			$this->writeJson($this->paths->layoutFile(), ["version" => self::VERSION]);
			if($this->exists($this->paths->migrationJournal()) && !@unlink($this->paths->migrationJournal())){
				throw new \RuntimeException("Unable to remove completed migration journal");
			}
			return $state;
		}finally{
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}

	private function migrate() : void{
		$journalPath = $this->paths->migrationJournal();
		$journal = $this->exists($journalPath) ? $this->readJson($journalPath) : ["version" => self::VERSION, "completed" => [], "copying" => null];
		if(!in_array($journal["version"] ?? null, [1, 2, self::VERSION], true) || !is_array($journal["completed"] ?? null)){
			throw new \RuntimeException("Invalid migration journal: $journalPath");
		}
		$moves = $this->moves();
		foreach($journal["completed"] as $step){
			if(!is_string($step) || !isset($moves[$step])){
				throw new \RuntimeException("Invalid completed migration step in $journalPath");
			}
		}
		if(($journal["copying"] ?? null) !== null && (!is_string($journal["copying"]) || !isset($moves[$journal["copying"]]))){
			throw new \RuntimeException("Invalid active migration step in $journalPath");
		}
		foreach(Utils::stringifyKeys($moves) as $source => $destination){
			$this->assertSafe($source);
			$this->assertSafe($destination);
			if($this->exists($source)){
				$this->assertTreeSafe($source);
			}
			if($this->exists($source) && $this->exists($destination) && ($journal["copying"] ?? null) !== $source){
				throw new \RuntimeException("Migration path collision: $source and $destination");
			}
		}
		$legacyConfig = $this->exists(Path::join($this->paths->root, "quark.yml")) ? Path::join($this->paths->root, "quark.yml") : $this->paths->quarkYml();
		$pluginDataMode = null;
		if($this->exists($legacyConfig)){
			$config = yaml_parse_file($legacyConfig);
			if(!is_array($config)){
				throw new \RuntimeException("Cannot read legacy plugin configuration: $legacyConfig");
			}
			$plugins = $config["plugins"] ?? [];
			$pluginDataMode = is_array($plugins) ? ($plugins["legacy-data-dir"] ?? null) : null;
			if($pluginDataMode === true){
				throw new \RuntimeException("Colocated plugin data needs a migration handler. Move plugin data to plugin_data and set plugins.legacy-data-dir to false before retrying.");
			}
		}
		if($pluginDataMode === null && is_file($this->paths->serverToml())){
			$modernPlugins = CoreConfig::load($this->paths->serverToml())["plugins"] ?? [];
			$pluginDataMode = is_array($modernPlugins) ? ($modernPlugins["legacy-data-dir"] ?? null) : null;
			if($pluginDataMode === true){
				throw new \RuntimeException("Colocated plugin data needs a migration handler. Move plugin data to data/plugins first.");
			}
		}
		if($pluginDataMode === null && !$this->exists(Path::join($this->paths->root, "plugin_data")) && is_dir($this->paths->plugins)){
			foreach(new \FilesystemIterator($this->paths->plugins, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $child){
				assert($child instanceof \SplFileInfo);
				if($child->isDir() || $child->isLink()){
					throw new \RuntimeException("Plugin data location is ambiguous. Set plugins.legacy-data-dir to false in quark.yml after verifying plugin data is in plugin_data, or migrate colocated data manually.");
				}
			}
		}
		$this->writeJson($journalPath, $journal);
		foreach(Utils::stringifyKeys($moves) as $source => $destination){
			if(in_array($source, $journal["completed"], true)){
				if($this->exists($source)){
					throw new \RuntimeException("Completed migration step still has legacy source: $source");
				}
				continue;
			}
			if(($journal["copying"] ?? null) === $source && ($journal["phase"] ?? null) === "retiring"){
				if(!$this->exists($destination) || ($journal["digest"] ?? null) !== $this->fingerprint($destination)){
					throw new \RuntimeException("Verified migration copy changed: $destination");
				}
			}
			if(!$this->exists($source)){
				if($this->exists($destination)){
					$journal["completed"][] = $source;
					$journal["copying"] = null;
					unset($journal["phase"], $journal["digest"]);
					$this->writeJson($journalPath, $journal);
				}elseif(($journal["copying"] ?? null) === $source){
					throw new \RuntimeException("Active migration step lost both source and destination: $source");
				}
				continue;
			}
			$this->makeDirectory(dirname($destination));
			if(($journal["copying"] ?? null) === $source && $this->exists($destination)){
				if(($journal["phase"] ?? null) === "retiring"){
					if(($journal["digest"] ?? null) !== $this->fingerprint($destination)){
						throw new \RuntimeException("Verified migration copy changed: $destination");
					}
					$this->verifyRemaining($source, $destination);
				}else{
					$this->verify($source, $destination);
					$journal["phase"] = "retiring";
					$journal["digest"] = $this->fingerprint($destination);
					$this->writeJson($journalPath, $journal);
				}
				$this->removeCopy($source);
			}elseif(!$this->exists($destination) && @rename($source, $destination)){
				// An interrupted rename is recognized by the destination on the next run.
			}else{
				$journal["copying"] = $source;
				$this->writeJson($journalPath, $journal);
				$free = disk_free_space(dirname($destination));
				if($free === false || $free < $this->size($source)){
					throw new \RuntimeException("Insufficient disk space to copy $source to $destination");
				}
				$tmp = $destination . ".quark-migration-copy";
				if($this->exists($tmp)){
					$this->removeCopy($tmp);
				}
				$this->copy($source, $tmp);
				$this->verify($source, $tmp);
				if(!@rename($tmp, $destination)){
					throw new \RuntimeException("Cannot publish verified copy at $destination");
				}
				$journal["phase"] = "retiring";
				$journal["digest"] = $this->fingerprint($destination);
				$this->writeJson($journalPath, $journal);
				$this->removeCopy($source);
			}
			$journal["completed"][] = $source;
			$journal["copying"] = null;
			unset($journal["phase"], $journal["digest"]);
			$this->writeJson($journalPath, $journal);
		}
	}

	private function assertTreeSafe(string $path) : void{
		$this->assertSafe($path);
		if(is_dir($path)){
			foreach(new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $child){
				assert($child instanceof \SplFileInfo);
				$this->assertTreeSafe($child->getPathname());
			}
		}
	}

	private function createDirectories() : void{
		foreach($this->requiredDirectories() as $path){
			$this->makeDirectory($path);
		}
	}

	/** @return list<string> */
	private function requiredDirectories() : array{
		return [$this->paths->config(), $this->paths->plugins, $this->paths->players(), $this->paths->worlds(), $this->paths->pluginData(), $this->paths->resourcePacks(), $this->paths->logs(), $this->paths->crashes(), $this->paths->profiles(), $this->paths->backups(), $this->paths->runtime()];
	}

	private function makeDirectory(string $path) : void{
		$this->assertSafe($path);
		if(!is_dir($path) && !@mkdir($path, 0777, true) && !is_dir($path)){
			throw new \RuntimeException("Cannot create directory: $path");
		}
	}

	/** @return array<mixed, mixed> */
	private function readJson(string $path) : array{
		$this->assertSafe($path);
		$data = @file_get_contents($path);
		$value = $data === false ? null : json_decode($data, true);
		if(!is_array($value)){
			throw new \RuntimeException("Invalid layout state: $path");
		}
		return $value;
	}

	/** @param array<string, mixed> $data */
	private function writeJson(string $path, array $data) : void{
		$tmp = $path . ".tmp";
		$this->assertSafe($path);
		$this->assertSafe($tmp);
		if(file_put_contents($tmp, json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX) === false || !@rename($tmp, $path)){
			throw new \RuntimeException("Cannot persist migration state: $path");
		}
	}

	private function size(string $path) : int{
		$this->assertSafe($path);
		if(!is_dir($path)){
			$size = filesize($path);
			return $size === false ? 0 : $size;
		}
		$total = 0;
		foreach(new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $child){
			assert($child instanceof \SplFileInfo);
			$total += $this->size($child->getPathname());
		}
		return $total;
	}

	private function copy(string $source, string $destination) : void{
		$this->assertSafe($source);
		if(is_dir($source)){
			$this->makeDirectory($destination);
			foreach(new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $child){
				assert($child instanceof \SplFileInfo);
				$this->copy($child->getPathname(), Path::join($destination, $child->getFilename()));
			}
		}elseif(!@copy($source, $destination)){
			throw new \RuntimeException("Cannot copy $source to $destination");
		}
		if(($permissions = @fileperms($source)) !== false){
			@chmod($destination, $permissions & 0777);
		}
		if(($modified = @filemtime($source)) !== false){
			@touch($destination, $modified);
		}
	}

	private function verify(string $source, string $destination) : void{
		$this->assertSafe($source);
		$this->assertSafe($destination);
		if(is_dir($source)){
			if(!is_dir($destination)){
				throw new \RuntimeException("Migration copy differs: $destination");
			}
			$names = [];
			foreach(new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $child){
				assert($child instanceof \SplFileInfo);
				$names[$child->getFilename()] = true;
				$this->verify($child->getPathname(), Path::join($destination, $child->getFilename()));
			}
			foreach(new \FilesystemIterator($destination, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $child){
				assert($child instanceof \SplFileInfo);
				if(!isset($names[$child->getFilename()])){
					throw new \RuntimeException("Migration copy has unexpected content: $destination");
				}
			}
		}elseif(!is_file($destination) || filesize($source) !== filesize($destination) || hash_file("sha256", $source) !== hash_file("sha256", $destination)){
			throw new \RuntimeException("Migration copy differs: $destination");
		}
	}

	private function verifyRemaining(string $source, string $destination) : void{
		$this->assertSafe($source);
		$this->assertSafe($destination);
		if(is_dir($source)){
			if(!is_dir($destination)){
				throw new \RuntimeException("Migration copy differs: $destination");
			}
			foreach(new \FilesystemIterator($source, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $child){
				assert($child instanceof \SplFileInfo);
				$this->verifyRemaining($child->getPathname(), Path::join($destination, $child->getFilename()));
			}
		}elseif(!is_file($destination) || filesize($source) !== filesize($destination) || hash_file("sha256", $source) !== hash_file("sha256", $destination)){
			throw new \RuntimeException("Migration copy differs: $destination");
		}
	}

	private function fingerprint(string $path) : string{
		$this->assertSafe($path);
		if(!is_dir($path)){
			$hash = hash_file("sha256", $path);
			if($hash === false){
				throw new \RuntimeException("Cannot verify migration copy: $path");
			}
			return "F" . $hash;
		}
		$names = [];
		foreach(new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $child){
			assert($child instanceof \SplFileInfo);
			$names[] = $child->getFilename();
		}
		sort($names, SORT_STRING);
		$hash = hash_init("sha256");
		foreach($names as $name){
			hash_update($hash, $name . "\0" . $this->fingerprint(Path::join($path, $name)) . "\0");
		}
		return "D" . hash_final($hash);
	}

	private function removeCopy(string $path) : void{
		$this->assertSafe($path);
		if(is_dir($path)){
			foreach(new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO) as $child){
				assert($child instanceof \SplFileInfo);
				$this->removeCopy($child->getPathname());
			}
			if(!@rmdir($path)){
				throw new \RuntimeException("Cannot retire verified legacy directory: $path");
			}
		}elseif(!@unlink($path)){
			throw new \RuntimeException("Cannot retire verified legacy file: $path");
		}
	}
}
