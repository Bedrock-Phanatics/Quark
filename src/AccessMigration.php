<?php

declare(strict_types=1);

namespace quark;

use quark\permission\AccessStorage;
use quark\permission\BanEntry;
use quark\utils\Utils;
use Symfony\Component\Filesystem\Path;
use function array_diff;
use function array_keys;
use function basename;
use function bin2hex;
use function copy;
use function count;
use function dirname;
use function disk_free_space;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filesize;
use function gmdate;
use function hash_file;
use function in_array;
use function is_array;
use function is_file;
use function is_link;
use function is_string;
use function json_decode;
use function json_encode;
use function mkdir;
use function preg_match;
use function random_bytes;
use function rename;
use function sort;
use function str_contains;
use function str_starts_with;
use function strlen;
use function strtolower;
use function trim;
use function unlink;
use const JSON_THROW_ON_ERROR;
use const LOCK_EX;

/**
 * Runs under the filesystem migration lock, before the server opens access.sqlite.
 */
final class AccessMigration{
	private const FILES = ["ops.txt", "white-list.txt", "banned-players.txt", "banned-ips.txt", "banned.txt"];

	public function __construct(private FilesystemPaths $paths){}

	private function journal() : string{ return Path::join($this->paths->root, ".quark-access-migration.json"); }

	private function safe(string $path) : void{
		for($part = $path; $part !== dirname($part); $part = dirname($part)){
			if(is_link($part)){
				throw new \RuntimeException("Access migration refuses symbolic link: $part");
			}
		}
	}

	/** @return array<string, string> */
	private function sources() : array{
		$sources = [];
		foreach(self::FILES as $name){
			$path = Path::join($this->paths->config(), $name);
			$this->safe($path);
			if(file_exists($path)){
				if(!is_file($path)){
					throw new \RuntimeException("Legacy access path is not a file: $path");
				}
				$sources[$name] = $path;
			}
		}
		if(isset($sources["banned.txt"], $sources["banned-players.txt"])){
			throw new \RuntimeException("Conflicting player ban files: banned.txt and banned-players.txt");
		}
		return $sources;
	}

	public function hasLegacy() : bool{
		if(is_file($this->journal()) || is_link($this->journal())){
			return true;
		}
		return $this->sources() !== [];
	}

	/** @return list<string> */
	public function dryRun() : array{
		$lines = [];
		foreach($this->sources() as $source){
			$lines[] = "SNAPSHOT $source";
		}
		if($lines !== []){
			$lines[] = "IMPORT to " . $this->paths->accessDatabase() . (file_exists($this->paths->accessDatabase()) ? " (COLLISION)" : "");
		}
		return $lines;
	}

	public function run() : void{
		$database = $this->paths->accessDatabase();
		$tmp = $database . ".migration-tmp";
		$this->safe($database);
		$this->safe($tmp);
		$this->safe($this->journal());
		$journal = is_file($this->journal()) ? $this->readJournal() : null;
		$sources = $this->sources();
		if($journal !== null && array_diff(array_keys($sources), array_keys($journal["files"])) !== []){
			throw new \RuntimeException("Legacy access files appeared after the migration snapshot; inspect them before retrying");
		}
		if($journal === null){
			if(file_exists($database) || is_link($database)){
				if($sources !== []){
					throw new \RuntimeException("Access migration collision: $database and legacy access files both exist");
				}
				$storage = new AccessStorage($database);
				$storage->close();
				return;
			}
			if(file_exists($tmp) || is_link($tmp)){
				throw new \RuntimeException("Untracked access migration file: $tmp");
			}
			if($sources === []){
				$storage = new AccessStorage($tmp, true, false);
				$storage->close();
				if(!@rename($tmp, $database)){
					throw new \RuntimeException("Cannot publish access database: $database");
				}
				return;
			}
			$journal = $this->snapshot($sources);
		}
		[$names, $bans] = $this->parseSnapshot($journal);
		if(file_exists($database) || is_link($database)){
			$this->verify($database, $names, $bans);
		}else{
			if(file_exists($tmp) || is_link($tmp)){
				if(!is_file($tmp) || !@unlink($tmp)){
					throw new \RuntimeException("Cannot reset interrupted access database: $tmp");
				}
			}
			$storage = new AccessStorage($tmp, true, false);
			try{
				$storage->transaction(function() use ($storage, $names, $bans) : void{
					foreach(Utils::stringifyKeys($names) as $kind => $entries){
						foreach($entries as $name){
							$storage->putName($kind, $name);
						}
					}
					foreach(Utils::stringifyKeys($bans) as $kind => $entries){
						foreach($entries as $entry){
							$storage->putBan($kind, $entry);
						}
					}
				});
			}finally{
				$storage->close();
			}
			$this->verify($tmp, $names, $bans);
			if(!@rename($tmp, $database)){
				throw new \RuntimeException("Cannot publish access database: $database");
			}
		}
		foreach(Utils::stringifyKeys($journal["files"]) as $name => $record){
			$source = $record["path"];
			$this->safe($source);
			if(is_file($source)){
				if(hash_file("sha256", $source) !== $record["hash"] || !@unlink($source)){
					throw new \RuntimeException("Cannot retire legacy access file: $source");
				}
			}
		}
		if(!@unlink($this->journal())){
			throw new \RuntimeException("Cannot remove access migration journal");
		}
	}

	/** @param array<string, string> $sources
	 * @return array{version: int, backup: string, created: string, files: array<string, array{path: string, hash: string}>}
	 */
	private function snapshot(array $sources) : array{
		$backup = Path::join($this->paths->backups(), "access-" . gmdate("Ymd-His") . "-" . bin2hex(random_bytes(4)));
		$this->safe($backup);
		$bytes = 0;
		foreach($sources as $source){
			$size = filesize($source);
			if($size === false){
				throw new \RuntimeException("Cannot size legacy access file: $source");
			}
			$bytes += $size;
		}
		$free = disk_free_space($this->paths->backups());
		if($free === false || $free < $bytes + 1048576){
			throw new \RuntimeException("Insufficient disk space for access migration snapshot");
		}
		if(!@mkdir($backup, 0777, true)){
			throw new \RuntimeException("Cannot create access migration snapshot: $backup");
		}
		$files = [];
		foreach(Utils::stringifyKeys($sources) as $name => $source){
			$target = Path::join($backup, $name);
			if(!@copy($source, $target) || ($hash = hash_file("sha256", $target)) === false || hash_file("sha256", $source) !== $hash){
				throw new \RuntimeException("Cannot verify access migration snapshot: $source");
			}
			$files[$name] = ["path" => $source, "hash" => $hash];
		}
		$journal = ["version" => 1, "backup" => $backup, "created" => (new \DateTime())->format(BanEntry::$format), "files" => $files];
		$path = $this->journal();
		$tmp = $path . ".tmp";
		$this->safe($tmp);
		$content = json_encode($journal, JSON_THROW_ON_ERROR);
		if(@file_put_contents($tmp, $content, LOCK_EX) !== strlen($content) || !@rename($tmp, $path)){
			throw new \RuntimeException("Cannot write access migration journal: $path");
		}
		return $journal;
	}

	/** @return array{version: int, backup: string, created: string, files: array<string, array{path: string, hash: string}>} */
	private function readJournal() : array{
		$path = $this->journal();
		$this->safe($path);
		$data = json_decode((string) @file_get_contents($path), true);
		if(!is_array($data) || ($data["version"] ?? null) !== 1 || !is_string($data["backup"] ?? null) || !is_string($data["created"] ?? null) || !is_array($data["files"] ?? null) || $data["files"] === []){
			throw new \RuntimeException("Invalid access migration journal: $path");
		}
		if(dirname($data["backup"]) !== $this->paths->backups() || !str_starts_with(basename($data["backup"]), "access-")){
			throw new \RuntimeException("Invalid access snapshot path in $path");
		}
		foreach(Utils::promoteKeys($data["files"]) as $name => $record){
			if(!in_array($name, self::FILES, true) || !is_array($record) || !is_string($record["path"] ?? null) || $record["path"] !== Path::join($this->paths->config(), $name) || !is_string($record["hash"] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $record["hash"]) !== 1){
				throw new \RuntimeException("Invalid access snapshot entry in $path");
			}
		}
		/** @var array{version: int, backup: string, created: string, files: array<string, array{path: string, hash: string}>} $data */
		return $data;
	}

	/** @param array{backup: string, created: string, files: array<string, array{path: string, hash: string}>} $journal
	 * @return array{array<string, list<string>>, array<string, array<string, BanEntry>>}
	 */
	private function parseSnapshot(array $journal) : array{
		$names = ["op" => [], "allow" => []];
		$bans = ["player" => [], "ip" => []];
		$created = \DateTime::createFromFormat(BanEntry::$format, $journal["created"]);
		if($created === false){
			throw new \RuntimeException("Invalid access migration creation time");
		}
		foreach(Utils::stringifyKeys($journal["files"]) as $name => $record){
			$path = Path::join($journal["backup"], $name);
			$this->safe($path);
			if(!is_file($path) || hash_file("sha256", $path) !== $record["hash"]){
				throw new \RuntimeException("Access migration snapshot changed: $path");
			}
			$source = $record["path"];
			$this->safe($source);
			if(is_file($source) && hash_file("sha256", $source) !== $record["hash"]){
				throw new \RuntimeException("Legacy access file changed during migration: $source");
			}
			$lineNumber = 0;
			$seen = [];
			foreach(new \SplFileObject($path) as $line){
				++$lineNumber;
				if(!is_string($line)){
					throw new \RuntimeException("Cannot read access migration snapshot: $path");
				}
					$line = trim($line);
					if($line === ""){
						continue;
					}
					if($name === "ops.txt" || $name === "white-list.txt"){
						$kind = $name === "ops.txt" ? "op" : "allow";
						$lookup = strtolower($line);
						if(isset($seen[$lookup])){
							throw new \RuntimeException("Conflicting access identity $line in $name");
						}
						$seen[$lookup] = true;
						$names[$kind][] = $line;
						continue;
					}
					if(str_starts_with($line, "#")){
						continue;
					}
					$kind = $name === "banned-ips.txt" ? "ip" : "player";
					try{
						$entry = BanEntry::fromString($line);
						if($entry === null || $entry->getName() === ""){
							throw new \RuntimeException("Empty ban identity");
						}
						if(!str_contains($line, "|")){
							$entry->setCreated(clone $created);
						}
						if(isset($bans[$kind][$entry->getName()])){
							throw new \RuntimeException("Duplicate ban identity: " . $entry->getName());
						}
						$bans[$kind][$entry->getName()] = $entry;
					}catch(\Throwable $e){
						throw new \RuntimeException("Invalid $name line $lineNumber: " . $e->getMessage(), 0, $e);
					}
			}
		}
		return [$names, $bans];
	}

	/** @param array<string, list<string>> $names
	 * @param array<string, array<string, BanEntry>> $bans
	 */
	private function verify(string $path, array $names, array $bans) : void{
		$storage = new AccessStorage($path, false, false);
		try{
			foreach(Utils::stringifyKeys($names) as $kind => $expected){
				$actual = array_keys($storage->loadNames($kind));
				sort($actual);
				sort($expected);
				if($actual !== $expected){
					throw new \RuntimeException("Access database differs from migration snapshot: $path");
				}
			}
			foreach(Utils::stringifyKeys($bans) as $kind => $expected){
				$actual = $storage->loadBans($kind);
				foreach(Utils::stringifyKeys($actual) as $name => $entry){
					if(!isset($expected[$name]) || $entry->getString() !== $expected[$name]->getString()){
						throw new \RuntimeException("Access database differs from migration snapshot: $path");
					}
				}
				if(count($actual) !== count($expected)){
					throw new \RuntimeException("Access database differs from migration snapshot: $path");
				}
			}
		}finally{
			$storage->close();
		}
	}
}
