<?php

declare(strict_types=1);

namespace quark;

use PHPUnit\Framework\TestCase;
use quark\permission\AccessStorage;
use function array_keys;
use function bin2hex;
use function copy;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function hash_file;
use function is_dir;
use function is_link;
use function json_encode;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

final class AccessMigrationTest extends TestCase{
	private string $root;
	private FilesystemPaths $paths;
	private FilesystemMigration $migration;

	protected function setUp() : void{
		$this->root = sys_get_temp_dir() . "/quark-access-migration-" . bin2hex(random_bytes(8));
		mkdir($this->root);
		$this->paths = new FilesystemPaths($this->root, $this->root . "/plugins");
		$this->migration = new FilesystemMigration($this->paths);
	}

	protected function tearDown() : void{
		$remove = static function(string $path) use (&$remove) : void{
			if(is_dir($path) && !is_link($path)){
				foreach(new \FilesystemIterator($path) as $child){
					self::assertInstanceOf(\SplFileInfo::class, $child);
					$remove($child->getPathname());
				}
				rmdir($path);
			}else{
				unlink($path);
			}
		};
		$remove($this->root);
	}

	public function testImportsAndSnapshotsAllLegacyLists() : void{
		file_put_contents($this->root . "/ops.txt", "Alice\n");
		file_put_contents($this->root . "/white-list.txt", "Bob\n");
		file_put_contents($this->root . "/banned-players.txt", "Griefer|2024-01-02 03:04:05 +0000|Console|Forever|reason|details\n");
		file_put_contents($this->root . "/banned-ips.txt", "192.0.2.1|2024-01-02 03:04:05 +0000|Admin|2030-01-02 03:04:05 +0000|spam\n");
		self::assertStringContainsString("SNAPSHOT", $this->migration->prepare(false, true));
		try{
			$this->migration->prepare();
			self::fail("Migration should be disabled");
		}catch(\RuntimeException $e){
			self::assertStringContainsString("--migrate-legacy", $e->getMessage());
		}
		self::assertSame("legacy", $this->migration->prepare(true));
		self::assertSame("modern", $this->migration->prepare());
		$storage = new AccessStorage($this->paths->accessDatabase());
		try{
			self::assertSame(["Alice"], array_keys($storage->loadNames("op")));
			self::assertSame(["Bob"], array_keys($storage->loadNames("allow")));
			self::assertSame("reason|details", $storage->loadBans("player")["griefer"]->getReason());
			self::assertSame("2030-01-02 03:04:05 +0000", $storage->loadBans("ip")["192.0.2.1"]->getExpires()?->format("Y-m-d H:i:s O"));
		}finally{
			$storage->close();
		}
		$backups = glob($this->paths->backups() . "/access-*");
		self::assertNotFalse($backups);
		self::assertCount(1, $backups);
		self::assertSame("Alice\n", file_get_contents($backups[0] . "/ops.txt"));
		self::assertFileDoesNotExist($this->paths->config() . "/ops.txt");
	}

	public function testMalformedRecordRetainsSourceAndRejectsRepeatedStartup() : void{
		file_put_contents($this->root . "/banned-players.txt", "Griefer|invalid date|Console|Forever|reason\n");
		try{
			$this->migration->prepare(true);
			self::fail("Malformed ban must fail migration");
		}catch(\RuntimeException $e){
			self::assertStringContainsString("banned-players.txt line 1", $e->getMessage());
		}
		self::assertFileExists($this->paths->config() . "/banned-players.txt");
		self::assertFileExists($this->root . "/.quark-access-migration.json");
		self::assertFileDoesNotExist($this->paths->accessDatabase());
		self::assertSame("partial", $this->migration->diagnose()["state"]);
		$this->expectExceptionMessage("--migrate-legacy");
		$this->migration->prepare();
	}

	public function testExistingDatabaseCollisionPreservesBothCopies() : void{
		$this->migration->prepare();
		file_put_contents($this->paths->config() . "/ops.txt", "Alice\n");
		$this->expectExceptionMessage("collision");
		try{
			$this->migration->prepare(true);
		}finally{
			self::assertFileExists($this->paths->accessDatabase());
			self::assertFileExists($this->paths->config() . "/ops.txt");
		}
	}

	public function testResumesFromSnapshotJournal() : void{
		mkdir($this->paths->config());
		mkdir($this->paths->backups());
		$source = $this->paths->config() . "/ops.txt";
		file_put_contents($source, "Alice\n");
		$backup = $this->paths->backups() . "/access-recovery";
		mkdir($backup);
		copy($source, $backup . "/ops.txt");
		file_put_contents($this->root . "/.quark-access-migration.json", json_encode([
			"version" => 1,
			"backup" => $backup,
			"created" => "2024-01-02 03:04:05 +0000",
			"files" => ["ops.txt" => ["path" => $source, "hash" => hash_file("sha256", $source)]]
		]));
		self::assertSame("partial", $this->migration->prepare(true));
		self::assertSame("modern", $this->migration->prepare());
		self::assertFileDoesNotExist($source);
		$storage = new AccessStorage($this->paths->accessDatabase());
		try{
			self::assertSame(["Alice"], array_keys($storage->loadNames("op")));
		}finally{
			$storage->close();
		}
	}
}
