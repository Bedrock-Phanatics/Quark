<?php

declare(strict_types=1);

namespace quark;

use PDO;
use PHPUnit\Framework\TestCase;
use quark\permission\AccessStorage;
use quark\permission\BanList;
use quark\utils\Config;
use function array_keys;
use function bin2hex;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

final class AccessStorageTest extends TestCase{
	private string $root;
	private AccessStorage $storage;

	protected function setUp() : void{
		$this->root = sys_get_temp_dir() . "/quark-access-" . bin2hex(random_bytes(8));
		mkdir($this->root);
		$this->storage = new AccessStorage($this->root . "/access.sqlite", true);
	}

	protected function tearDown() : void{
		$this->storage->close();
		foreach(new \FilesystemIterator($this->root) as $file){
			self::assertInstanceOf(\SplFileInfo::class, $file);
			unlink($file->getPathname());
		}
		rmdir($this->root);
	}

	public function testCachedNamesAndBanMetadataSurviveRestart() : void{
		$ops = new Config($this->storage->path(), Config::ENUM, [], $this->storage, "op");
		$ops->set("Alice");
		self::assertTrue($ops->exists("ALICE", true));
		$ops->set("aLiCe");
		self::assertSame(["aLiCe"], $ops->getAll(true));
		$allow = new Config($this->storage->path(), Config::ENUM, [], $this->storage, "allow");
		$allow->set("Bob");
		$ban = new BanList($this->storage, "player");
		$ban->load();
		$expires = new \DateTime("2030-01-02 03:04:05 +0000");
		$entry = $ban->addBan("Griefer", "reason|with separator", $expires, "Console");
		$serialized = $entry->getString();
		self::assertTrue($ban->isBanned("GRIEFER"));
		$this->storage->close();
		$this->storage = new AccessStorage($this->root . "/access.sqlite");
		self::assertSame(["aLiCe"], array_keys($this->storage->loadNames("op")));
		self::assertSame(["Bob"], array_keys($this->storage->loadNames("allow")));
		$reloaded = new BanList($this->storage, "player");
		$reloaded->load();
		self::assertSame($serialized, $reloaded->getEntry("gRiEfEr")?->getString());
		self::assertSame("reason|with separator", $reloaded->getEntry("griefer")?->getReason());
	}

	public function testLockedWriteLeavesCacheConsistentAndCanRetry() : void{
		$ops = new Config($this->storage->path(), Config::ENUM, [], $this->storage, "op");
		$other = new PDO("sqlite:" . $this->storage->path());
		$other->exec("BEGIN IMMEDIATE");
		try{
			try{
				$ops->set("Blocked");
				self::fail("Expected bounded SQLite busy failure");
			}catch(\PDOException){
				self::assertFalse($ops->exists("Blocked", true));
			}
		}finally{
			$other->exec("ROLLBACK");
		}
		$ops->set("Blocked");
		self::assertTrue($ops->exists("blocked", true));
	}
}
