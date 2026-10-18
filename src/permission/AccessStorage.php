<?php

declare(strict_types=1);

namespace quark\permission;

use PDO;
use quark\utils\Utils;
use function file_exists;
use function is_array;
use function is_file;
use function is_link;
use function is_string;
use function strtolower;

final class AccessStorage{
	public const VERSION = 1;

	private ?PDO $db;
	private bool $wal = false;

	public function __construct(private string $path, bool $create = false, bool $useWal = true){
		if(is_link($path) || !$create && !is_file($path) || $create && file_exists($path)){
			throw new \RuntimeException("Invalid access database path: $path");
		}
		$this->db = new PDO("sqlite:$path", null, null, [
			PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
			PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
			PDO::ATTR_EMULATE_PREPARES => false
		]);
		$this->db->exec("PRAGMA busy_timeout = 1000");
		$modeQuery = $this->db->query("PRAGMA journal_mode = " . ($useWal ? "WAL" : "DELETE"));
		if($modeQuery === false){
			throw new \RuntimeException("Cannot set SQLite journal mode for $path");
		}
		$mode = strtolower((string) $modeQuery->fetchColumn());
		$this->wal = $mode === "wal";
		if(!$this->wal && $mode !== "delete"){
			throw new \RuntimeException("Unsupported SQLite journal mode for $path: $mode");
		}
		$this->db->exec("PRAGMA synchronous = FULL");
		if($create){
			$this->db->exec("CREATE TABLE names (kind TEXT NOT NULL CHECK (kind IN ('op', 'allow')), identity TEXT NOT NULL, lookup TEXT NOT NULL, PRIMARY KEY (kind, lookup)) WITHOUT ROWID");
			$this->db->exec("CREATE TABLE bans (kind TEXT NOT NULL CHECK (kind IN ('player', 'ip')), identity TEXT NOT NULL, created TEXT NOT NULL, source TEXT NOT NULL, expires TEXT, reason TEXT NOT NULL, PRIMARY KEY (kind, identity)) WITHOUT ROWID");
			$this->db->exec("PRAGMA user_version = " . self::VERSION);
		}else{
			$versionQuery = $this->db->query("PRAGMA user_version");
			if($versionQuery === false){
				throw new \RuntimeException("Cannot read access database schema version: $path");
			}
			$version = (int) $versionQuery->fetchColumn();
			if($version !== self::VERSION){
				throw new \RuntimeException("Unsupported access database schema version $version in $path");
			}
		}
	}

	public function path() : string{ return $this->path; }

	private function db() : PDO{
		return $this->db ?? throw new \LogicException("Access database is closed");
	}

	/** @return array<int|string, true> */
	public function loadNames(string $kind) : array{
		$statement = $this->db()->prepare("SELECT identity FROM names WHERE kind = ? ORDER BY identity");
		$statement->execute([$kind]);
		$result = [];
		while(($name = $statement->fetchColumn()) !== false){
			$result[$name] = true;
		}
		return $result;
	}

	public function putName(string $kind, string $name) : void{
		$this->db()->prepare("INSERT INTO names(kind, identity, lookup) VALUES (?, ?, ?) ON CONFLICT(kind, lookup) DO UPDATE SET identity = excluded.identity")
			->execute([$kind, $name, strtolower($name)]);
	}

	public function removeName(string $kind, string $name) : void{
		$this->db()->prepare("DELETE FROM names WHERE kind = ? AND lookup = ?")->execute([$kind, strtolower($name)]);
	}

	/** @param array<string, mixed> $names */
	public function replaceNames(string $kind, array $names) : void{
		$this->transaction(function() use ($kind, $names) : void{
			$this->db()->prepare("DELETE FROM names WHERE kind = ?")->execute([$kind]);
			$insert = $this->db()->prepare("INSERT INTO names(kind, identity, lookup) VALUES (?, ?, ?)");
			foreach(Utils::stringifyKeys($names) as $name => $_){
				$insert->execute([$kind, (string) $name, strtolower((string) $name)]);
			}
		});
	}

	/** @return array<string, BanEntry> */
	public function loadBans(string $kind) : array{
		$statement = $this->db()->prepare("SELECT identity, created, source, expires, reason FROM bans WHERE kind = ?");
		$statement->execute([$kind]);
		$result = [];
		while(($row = $statement->fetch()) !== false){
			if(!is_array($row) || !is_string($row["identity"] ?? null) || !is_string($row["created"] ?? null) || !is_string($row["source"] ?? null) || !is_string($row["reason"] ?? null) || !(is_string($row["expires"] ?? null) || ($row["expires"] ?? null) === null)){
				throw new \RuntimeException("Invalid ban record in access database: " . $this->path);
			}
			$expires = $row["expires"];
			/** @var string|null $expires */
			$entry = BanEntry::fromStorage($row["identity"], $row["created"], $row["source"], $expires, $row["reason"]);
			$result[$entry->getName()] = $entry;
		}
		return $result;
	}

	public function putBan(string $kind, BanEntry $entry) : void{
		$this->db()->prepare("INSERT INTO bans(kind, identity, created, source, expires, reason) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(kind, identity) DO UPDATE SET created = excluded.created, source = excluded.source, expires = excluded.expires, reason = excluded.reason")
			->execute([$kind, $entry->getName(), $entry->getCreated()->format(BanEntry::$format), $entry->getSource(), $entry->getExpires()?->format(BanEntry::$format), $entry->getReason()]);
	}

	public function removeBan(string $kind, string $name) : void{
		$this->db()->prepare("DELETE FROM bans WHERE kind = ? AND identity = ?")->execute([$kind, strtolower($name)]);
	}

	/** @param array<string, BanEntry> $entries */
	public function replaceBans(string $kind, array $entries) : void{
		$this->transaction(function() use ($kind, $entries) : void{
			$this->db()->prepare("DELETE FROM bans WHERE kind = ?")->execute([$kind]);
			foreach($entries as $entry){
				$this->putBan($kind, $entry);
			}
		});
	}

	/** @param callable(): void $work */
	public function transaction(callable $work) : void{
		$db = $this->db();
		$db->exec("BEGIN IMMEDIATE");
		try{
			$work();
			$db->exec("COMMIT");
		}catch(\Throwable $e){
			try{
				$db->exec("ROLLBACK");
			}catch(\Throwable){}
			throw $e;
		}
	}

	public function close() : void{
		if($this->db !== null){
			try{
				if($this->wal){
					$checkpoint = $this->db->query("PRAGMA wal_checkpoint(PASSIVE)");
					if($checkpoint !== false){
						$checkpoint->closeCursor();
					}
				}
			}catch(\PDOException){} // The WAL remains valid if another connection prevents a checkpoint.
			finally{
				$this->db = null;
			}
		}
	}

	public function __destruct(){
		try{
			$this->close();
		}catch(\Throwable){}
	}
}
