<?php

declare(strict_types=1);

namespace quark;

use PHPUnit\Framework\TestCase;

final class FilesystemMigrationTest extends TestCase{
	private string $root;
	private FilesystemPaths $paths;
	private FilesystemMigration $migration;

	protected function setUp() : void{
		$this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "quark-layout-" . bin2hex(random_bytes(8));
		mkdir($this->root);
		$this->paths = new FilesystemPaths($this->root, $this->root . DIRECTORY_SEPARATOR . "plugins");
		$this->migration = new FilesystemMigration($this->paths);
	}

	protected function tearDown() : void{
		$remove = static function(string $path) use (&$remove) : void{
			if(is_dir($path) && !is_link($path)){
				foreach(new \FilesystemIterator($path) as $child){
					assert($child instanceof \SplFileInfo);
					$remove($child->getPathname());
				}
				rmdir($path);
			}else{
				unlink($path);
			}
		};
		$remove($this->root);
	}

	public function testFreshAndRepeatedStartup() : void{
		self::assertSame("empty", $this->migration->prepare());
		self::assertSame("modern", $this->migration->prepare());
		$layout = file_get_contents($this->paths->layoutFile());
		self::assertIsString($layout);
		$data = json_decode($layout, true);
		self::assertIsArray($data);
		self::assertSame(1, $data["version"]);
		self::assertDirectoryExists($this->paths->players());
		self::assertDirectoryExists($this->paths->pluginData());
	}

	public function testDisabledMigrationAndDryRunDoNotMoveData() : void{
		file_put_contents($this->root . "/server.properties", "motd=legacy");
		self::assertSame("legacy", $this->migration->diagnose()["state"]);
		self::assertStringContainsString("server.properties", $this->migration->prepare(false, true));
		self::assertFileDoesNotExist($this->paths->migrationLock());
		$this->expectExceptionMessage("--migrate-legacy");
		$this->migration->prepare();
	}

	public function testMigrationAndRecoveryAfterInterruptedRename() : void{
		mkdir($this->root . "/worlds");
		file_put_contents($this->root . "/worlds/level.dat", "world data");
		file_put_contents($this->root . "/server.properties", "motd=legacy");
		self::assertSame("legacy", $this->migration->diagnose()["state"]);
		mkdir($this->paths->config());
		file_put_contents($this->paths->migrationJournal(), json_encode(["version" => 1, "completed" => [], "copying" => null]));
		rename($this->root . "/server.properties", $this->paths->serverProperties());
		self::assertSame("partial", $this->migration->diagnose()["state"]);
		self::assertSame("partial", $this->migration->prepare(true));
		self::assertSame("world data", file_get_contents($this->paths->worlds() . "/level.dat"));
		self::assertSame("motd=legacy", file_get_contents($this->paths->serverProperties()));
		self::assertSame("modern", $this->migration->prepare());
	}

	public function testCollisionIsRejectedBeforeMovingAnything() : void{
		file_put_contents($this->root . "/server.properties", "old");
		mkdir($this->paths->config());
		file_put_contents($this->paths->serverProperties(), "new");
		$this->expectExceptionMessage("collision");
		try{
			$this->migration->prepare(true);
		}finally{
			self::assertSame("old", file_get_contents($this->root . "/server.properties"));
		}
	}

	public function testResumesVerifiedCopyWithoutDeletingUnverifiedSource() : void{
		file_put_contents($this->root . "/server.properties", "old");
		mkdir($this->paths->config());
		file_put_contents($this->paths->serverProperties(), "wrong");
		file_put_contents($this->paths->migrationJournal(), json_encode(["version" => 1, "completed" => [], "copying" => \Symfony\Component\Filesystem\Path::join($this->root, "server.properties")]));
		try{
			$this->migration->prepare(true);
			self::fail("Expected verification failure");
		}catch(\RuntimeException $e){
			self::assertStringContainsString("differs", $e->getMessage());
		}
		self::assertSame("old", file_get_contents($this->root . "/server.properties"));
		file_put_contents($this->paths->serverProperties(), "old");
		self::assertSame("partial", $this->migration->prepare(true));
		self::assertFileDoesNotExist($this->root . "/server.properties");
		self::assertSame("old", file_get_contents($this->paths->serverProperties()));
	}

	public function testResumesInterruptedCopyRetirement() : void{
		mkdir($this->root . "/worlds");
		file_put_contents($this->root . "/worlds/remaining.dat", "data");
		mkdir($this->root . "/data");
		mkdir($this->paths->worlds());
		file_put_contents($this->paths->worlds() . "/remaining.dat", "data");
		file_put_contents($this->paths->worlds() . "/already-retired.dat", "prior data");
		$digest = (new \ReflectionMethod(FilesystemMigration::class, "fingerprint"))->invoke($this->migration, $this->paths->worlds());
		file_put_contents($this->paths->migrationJournal(), json_encode(["version" => 1, "completed" => [], "copying" => \Symfony\Component\Filesystem\Path::join($this->root, "worlds"), "phase" => "retiring", "digest" => $digest]));
		self::assertSame("partial", $this->migration->prepare(true));
		self::assertFileDoesNotExist($this->root . "/worlds");
		self::assertSame("prior data", file_get_contents($this->paths->worlds() . "/already-retired.dat"));
	}

	public function testModernMarkerWithMissingDirectoryIsRejected() : void{
		$this->migration->prepare();
		rmdir($this->paths->worlds());
		$this->expectExceptionMessage("incomplete");
		$this->migration->prepare(true);
	}

	public function testMixedLayoutCanMigrateWithoutCollision() : void{
		file_put_contents($this->root . "/server.properties", "old");
		mkdir($this->paths->config());
		file_put_contents($this->paths->config() . "/migration.ini", "[migration]\nauto_on_startup = true\n");
		self::assertSame("mixed", $this->migration->diagnose()["state"]);
		self::assertSame("mixed", $this->migration->prepare(true));
		self::assertSame("old", file_get_contents($this->paths->serverProperties()));
	}

	public function testColocatedPluginDataNeedsHandler() : void{
		if(!function_exists("yaml_parse_file")){
			self::markTestSkipped("Requires the YAML extension");
		}
		file_put_contents($this->root . "/server.properties", "old");
		file_put_contents($this->root . "/quark.yml", "plugins:\n  legacy-data-dir: true\n");
		$this->expectExceptionMessage("needs a migration handler");
		$this->migration->prepare(true);
	}

	public function testMigrationLockIsExclusive() : void{
		$lock = fopen($this->paths->migrationLock(), "c+b");
		self::assertIsResource($lock);
		self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
		try{
			$this->expectExceptionMessage("Another filesystem migration");
			$this->migration->prepare();
		}finally{
			flock($lock, LOCK_UN);
			fclose($lock);
		}
	}
}
