<?php

declare(strict_types=1);

namespace quark;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;
use quark\permission\AccessStorage;
use quark\player\DatFilePlayerDataProvider;
use quark\utils\Filesystem;
use quark\world\format\io\data\BedrockWorldData;
use quark\world\format\io\WorldProviderManager;
use quark\world\WorldCreationOptions;
use function assert;
use function bin2hex;
use function copy;
use function dirname;
use function extension_loaded;
use function fclose;
use function fgets;
use function file_get_contents;
use function file_put_contents;
use function flock;
use function fopen;
use function function_exists;
use function glob;
use function hash_file;
use function is_array;
use function is_dir;
use function is_link;
use function json_decode;
use function json_encode;
use function mkdir;
use function proc_close;
use function proc_open;
use function proc_terminate;
use function random_bytes;
use function rename;
use function rmdir;
use function symlink;
use function sys_get_temp_dir;
use function unlink;
use function var_export;
use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;
use const PHP_BINARY;

final class FilesystemMigrationTest extends TestCase{
	private string $root;
	private FilesystemPaths $paths;
	private FilesystemMigration $migration;

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private static function table(array $data, string $key) : array{
		$value = $data[$key] ?? null;
		if(!is_array($value)){
			throw new \RuntimeException("Missing table: $key");
		}
		/** @var array<string, mixed> $value */
		return $value;
	}

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
		mkdir($this->paths->runtime());
		self::assertSame("empty", $this->migration->prepare());
		self::assertSame("modern", $this->migration->prepare());
		$layout = file_get_contents($this->paths->layoutFile());
		self::assertIsString($layout);
		$data = json_decode($layout, true);
		self::assertIsArray($data);
		self::assertSame(4, $data["version"]);
		self::assertFileExists($this->paths->accessDatabase());
		self::assertDirectoryExists($this->paths->players());
		self::assertDirectoryExists($this->paths->pluginData());
		self::assertDirectoryExists($this->paths->runtimeCache());
		self::assertDirectoryExists($this->paths->runtimeTmp());
	}

	public function testVersionThreeRequiresUpgradeAndPreservesRestoreData() : void{
		$this->migration->prepare();
		file_put_contents($this->paths->layoutFile(), json_encode(["version" => 3]));
		rmdir($this->paths->runtimeCache());
		rmdir($this->paths->runtimeTmp());
		mkdir($this->root . "/restore");
		file_put_contents($this->root . "/restore/unknown.dat", "keep");
		self::assertSame("mixed", $this->migration->diagnose()["state"]);
		try{
			$this->migration->prepare();
			self::fail("The version upgrade must require migration permission");
		}catch(\RuntimeException $e){
			self::assertStringContainsString("--migrate-legacy", $e->getMessage());
		}
		$this->migration->prepare(true);
		self::assertSame("modern", $this->migration->prepare());
		self::assertSame("keep", file_get_contents($this->root . "/restore/unknown.dat"));
	}

	public function testServerLockSurvivesReleaseAndCanBeReacquired() : void{
		mkdir($this->paths->runtime());
		$path = $this->paths->serverLock();
		self::assertNull(Filesystem::createLockFile($path));
		try{
			self::assertSame(-1, Filesystem::createLockFile($path));
		}finally{
			Filesystem::releaseLockFile($path);
		}
		self::assertFileExists($path);
		self::assertNull(Filesystem::createLockFile($path));
		Filesystem::releaseLockFile($path);
	}

	public function testServerLockIsReleasedAfterProcessTermination() : void{
		if(!function_exists("proc_open")){
			self::markTestSkipped("Process creation is unavailable");
		}
		mkdir($this->paths->runtime());
		$path = $this->paths->serverLock();
		$autoload = dirname(__DIR__, 2) . "/vendor/autoload.php";
		$script = "require " . var_export($autoload, true) . "; quark\\utils\\Filesystem::createLockFile(" . var_export($path, true) . "); echo getmypid(), PHP_EOL; fflush(STDOUT); fgets(STDIN);";
		$process = proc_open([PHP_BINARY, "-r", $script], [["pipe", "r"], ["pipe", "w"], ["pipe", "w"]], $pipes);
		self::assertIsResource($process);
		try{
			$pid = fgets($pipes[1]);
			self::assertIsString($pid);
			self::assertSame(-1, Filesystem::createLockFile($path));
		}finally{
			proc_terminate($process);
			foreach($pipes as $pipe){
				fclose($pipe);
			}
			proc_close($process);
		}
		self::assertNull(Filesystem::createLockFile($path));
		Filesystem::releaseLockFile($path);
	}

	public function testDisabledMigrationAndDryRunDoNotMoveData() : void{
		file_put_contents($this->root . "/server.properties", "motd=legacy");
		self::assertSame("legacy", $this->migration->diagnose()["state"]);
		$diagnostics = $this->migration->prepare(false, true);
		self::assertStringContainsString("server.properties", $diagnostics);
		self::assertStringContainsString("SNAPSHOT", $diagnostics);
		self::assertFileDoesNotExist($this->paths->migrationLock());
		$this->expectExceptionMessage("--migrate-legacy");
		$this->migration->prepare();
	}

	public function testLegacyDebugMigratesToNamedDestinationLevels() : void{
		file_put_contents($this->root . "/quark.yml", "debug:\n  level: 2\n");
		$this->migration->prepare(true);
		$config = CoreConfig::load($this->paths->serverToml());
		self::assertArrayNotHasKey("debug", $config);
		$logging = self::table($config, "logging");
		self::assertSame("debug", $logging["console-level"]);
		self::assertSame("debug", $logging["file-level"]);
	}

	public function testLegacyDebugPreservesExplicitDestinationLevels() : void{
		file_put_contents($this->root . "/quark.yml", "debug:\n  level: 2\nlogging:\n  console-level: warning\n");
		$this->migration->prepare(true);
		$logging = self::table(CoreConfig::load($this->paths->serverToml()), "logging");
		self::assertSame("warning", $logging["console-level"]);
		self::assertSame("debug", $logging["file-level"]);
	}

	public function testCompleteLegacyInstallationMigratesAndRestartsWithoutChangingData() : void{
		$originals = [
			"pocketmine.yml" => "settings:\n  async-workers: 2\n",
			"quark.yml" => "plugins:\n  legacy-data-dir: false\nsettings:\n  force-language: true\n",
			"server.properties" => "motd=Integrated Legacy\nlevel-name=Lobby\nmax-players=12\n",
			"plugin_list.yml" => "mode: whitelist\nplugins:\n  - Example\n",
			"ops.txt" => "Alice\n",
			"white-list.txt" => "Bob\n",
			"banned-players.txt" => "Griefer|2024-01-02 03:04:05 +0000|Console|Forever|griefing\n",
			"banned-ips.txt" => "192.0.2.1|2024-01-02 03:04:05 +0000|Admin|2030-01-02 03:04:05 +0000|spam\n",
			"plugin_data/Example/config.yml" => "enabled: true\n",
			"plugin_data/unknown.bin" => "unknown plugin data",
			"resource_packs/Example.mcpack" => random_bytes(4096),
			"resource_packs/resource_packs.yml" => "resource_stack: []\n",
			"server.log" => "old log\n",
			"log_archive/old.log" => "old archive\n",
			"crashdumps/old.txt" => "old crash\n",
			"pulse/old.qpulse" => "old profile\n",
			"timings/old.json" => "old timings\n",
			"memory_dumps/old.bin" => "old memory dump"
		];
		foreach($originals as $name => $contents){
			$path = $this->root . "/$name";
			if(!is_dir(dirname($path))){
				mkdir(dirname($path), 0777, true);
			}
			file_put_contents($path, $contents);
		}
		mkdir($this->paths->plugins);
		file_put_contents($this->paths->plugins . "/Example.phar", "installed package");
		$legacyWorld = $this->root . "/worlds/Lobby";
		mkdir($legacyWorld . "/db", 0777, true);
		BedrockWorldData::generate($legacyWorld, "Lobby", WorldCreationOptions::create()->setSeed(42));
		$worldHash = hash_file("sha256", $legacyWorld . "/level.dat");
		mkdir($this->root . "/players");
		$players = new DatFilePlayerDataProvider($this->root . "/players");
		$players->saveData("Alice", CompoundTag::create()->setString("name", "Alice"));
		$playerHash = hash_file("sha256", $this->root . "/players/alice.dat");
		mkdir($this->root . "/restore");
		file_put_contents($this->root . "/restore/keep.bin", "unclassified recovery data");

		self::assertSame("legacy", $this->migration->diagnose()["state"]);
		try{
			$this->migration->prepare();
			self::fail("Disabled migration must reject the complete legacy installation");
		}catch(\RuntimeException $e){
			self::assertStringContainsString("--migrate-legacy", $e->getMessage());
		}
		foreach($originals as $name => $contents){
			self::assertSame($contents, file_get_contents($this->root . "/$name"));
		}
		self::assertSame($worldHash, hash_file("sha256", $legacyWorld . "/level.dat"));
		self::assertSame($playerHash, hash_file("sha256", $this->root . "/players/alice.dat"));
		self::assertFileDoesNotExist($this->paths->layoutFile());

		self::assertSame("legacy", $this->migration->prepare(true));
		self::assertSame("modern", (new FilesystemMigration($this->paths))->prepare());
		self::assertSame("modern", (new FilesystemMigration($this->paths))->prepare());
		foreach([$this->paths->config(), $this->paths->plugins, $this->paths->players(), $this->paths->worlds(), $this->paths->pluginData(), $this->paths->resourcePacks(), $this->paths->logs(), $this->paths->crashes(), $this->paths->profiles(), $this->paths->backups(), $this->paths->runtime(), $this->paths->runtimeCache(), $this->paths->runtimeTmp()] as $path){
			self::assertDirectoryExists($path);
		}
		self::assertSame("installed package", file_get_contents($this->paths->plugins . "/Example.phar"));
		self::assertSame("enabled: true\n", file_get_contents($this->paths->pluginData() . "/Example/config.yml"));
		self::assertSame("unknown plugin data", file_get_contents($this->paths->pluginData() . "/unknown.bin"));
		self::assertSame($originals["resource_packs/Example.mcpack"], file_get_contents($this->paths->resourcePacks() . "/Example.mcpack"));
		self::assertSame("old log\n", file_get_contents($this->paths->logs() . "/server.log"));
		self::assertSame("old archive\n", file_get_contents($this->paths->logs() . "/archive/old.log"));
		self::assertSame("old crash\n", file_get_contents($this->paths->crashes() . "/old.txt"));
		self::assertSame("old profile\n", file_get_contents($this->paths->profiles() . "/old.qpulse"));
		self::assertSame("old timings\n", file_get_contents($this->paths->profiles() . "/timings/old.json"));
		self::assertSame("old memory dump", file_get_contents($this->paths->profiles() . "/memory_dumps/old.bin"));
		self::assertSame("unclassified recovery data", file_get_contents($this->root . "/restore/keep.bin"));
		self::assertSame($worldHash, hash_file("sha256", $this->paths->worlds() . "/Lobby/level.dat"));
		self::assertSame($playerHash, hash_file("sha256", $this->paths->players() . "/alice.dat"));
		self::assertSame("Alice", (new DatFilePlayerDataProvider($this->paths->players()))->loadData("alice")?->getString("name"));
		$server = self::table(CoreConfig::load($this->paths->serverToml()), "server");
		self::assertSame("Integrated Legacy", $server["motd"]);
		self::assertSame("Lobby", $server["level-name"]);
		self::assertSame(12, $server["max-players"]);
		self::assertTrue(self::table(CoreConfig::load($this->paths->serverToml()), "settings")["force-language"]);
		self::assertSame(2, self::table(CoreConfig::load($this->paths->serverToml()), "settings")["async-workers"]);
		self::assertSame(["Example"], CoreConfig::load($this->paths->pluginsToml())["plugins"]);
		$access = new AccessStorage($this->paths->accessDatabase());
		try{
			self::assertArrayHasKey("Alice", $access->loadNames("op"));
			self::assertArrayHasKey("Bob", $access->loadNames("allow"));
			self::assertSame("griefing", $access->loadBans("player")["griefer"]->getReason());
			self::assertSame("spam", $access->loadBans("ip")["192.0.2.1"]->getReason());
		}finally{
			$access->close();
		}
		$configBackups = glob($this->paths->backups() . "/config-*");
		$accessBackups = glob($this->paths->backups() . "/access-*");
		self::assertIsArray($configBackups);
		self::assertIsArray($accessBackups);
		self::assertCount(1, $configBackups);
		self::assertCount(1, $accessBackups);
	}

	public function testLegacyWorldAndPlayerDirectoriesRequireMigration() : void{
		mkdir($this->root . "/worlds");
		mkdir($this->root . "/players");
		file_put_contents($this->root . "/worlds/level.dat", "world");
		file_put_contents($this->root . "/players/alice.dat", "player");
		self::assertSame("legacy", $this->migration->diagnose()["state"]);
		try{
			$this->migration->prepare();
			self::fail("Legacy storage must not boot without migration");
		}catch(\RuntimeException $e){
			self::assertStringContainsString("--migrate-legacy", $e->getMessage());
		}
		self::assertFileExists($this->root . "/worlds/level.dat");
		self::assertFileExists($this->root . "/players/alice.dat");
	}

	public function testWorldDirectoryCollisionDoesNotOverwriteEitherCopy() : void{
		mkdir($this->root . "/worlds");
		file_put_contents($this->root . "/worlds/level.dat", "legacy");
		mkdir($this->paths->worlds(), 0777, true);
		file_put_contents($this->paths->worlds() . "/level.dat", "modern");
		try{
			$this->migration->prepare(true);
			self::fail("World collision must stop migration");
		}catch(\RuntimeException $e){
			self::assertStringContainsString("collision", $e->getMessage());
		}
		self::assertSame("legacy", file_get_contents($this->root . "/worlds/level.dat"));
		self::assertSame("modern", file_get_contents($this->paths->worlds() . "/level.dat"));
	}

	public function testPluginDataMovesWithoutTouchingPackagesOrUnknownFiles() : void{
		mkdir($this->root . "/plugin_data/Example", 0777, true);
		file_put_contents($this->root . "/plugin_data/Example/config.yml", "enabled: true\n");
		file_put_contents($this->root . "/plugin_data/unknown.bin", "opaque data");
		mkdir($this->paths->plugins);
		file_put_contents($this->paths->plugins . "/Example.phar", "package bytes");
		self::assertStringContainsString("getDataFolder()", $this->migration->prepare(false, true));
		self::assertSame("legacy", $this->migration->prepare(true));
		self::assertSame("modern", $this->migration->prepare());
		self::assertSame("enabled: true\n", file_get_contents($this->paths->pluginData() . "/Example/config.yml"));
		self::assertSame("opaque data", file_get_contents($this->paths->pluginData() . "/unknown.bin"));
		self::assertSame("package bytes", file_get_contents($this->paths->plugins . "/Example.phar"));
		self::assertDirectoryDoesNotExist($this->root . "/plugin_data");
	}

	public function testPluginDataCollisionRetainsBothDirectories() : void{
		mkdir($this->root . "/plugin_data");
		file_put_contents($this->root . "/plugin_data/legacy.bin", "legacy");
		mkdir($this->paths->pluginData(), 0777, true);
		file_put_contents($this->paths->pluginData() . "/modern.bin", "modern");
		try{
			$this->migration->prepare(true);
			self::fail("Plugin data collision must stop migration");
		}catch(\RuntimeException $e){
			self::assertStringContainsString("collision", $e->getMessage());
		}
		self::assertSame("legacy", file_get_contents($this->root . "/plugin_data/legacy.bin"));
		self::assertSame("modern", file_get_contents($this->paths->pluginData() . "/modern.bin"));
	}

	public function testPluginDataInterruptedRenameResumes() : void{
		mkdir($this->root . "/plugin_data");
		file_put_contents($this->root . "/plugin_data/unknown.bin", "persistent");
		mkdir(dirname($this->paths->pluginData()));
		file_put_contents($this->paths->migrationJournal(), json_encode(["version" => 3, "completed" => [], "copying" => null]));
		rename($this->root . "/plugin_data", $this->paths->pluginData());
		self::assertSame("partial", $this->migration->prepare(true));
		self::assertSame("modern", $this->migration->prepare());
		self::assertSame("persistent", file_get_contents($this->paths->pluginData() . "/unknown.bin"));
	}

	public function testResourcePacksMoveWithoutChangingFilesAndRestart() : void{
		mkdir($this->root . "/resource_packs");
		file_put_contents($this->root . "/resource_packs/resource_packs.yml", "resource_stack:\n  - Example.mcpack\n");
		file_put_contents($this->root . "/resource_packs/Example.mcpack", random_bytes(1024));
		file_put_contents($this->root . "/resource_packs/Example.mcpack.key", "12345678901234567890123456789012");
		$before = hash_file("sha256", $this->root . "/resource_packs/Example.mcpack");
		self::assertSame("legacy", $this->migration->diagnose()["state"]);
		self::assertStringContainsString("resource_packs", $this->migration->prepare(false, true));
		try{
			$this->migration->prepare();
			self::fail("Legacy resource packs must require migration");
		}catch(\RuntimeException $e){
			self::assertStringContainsString("--migrate-legacy", $e->getMessage());
		}
		self::assertSame("legacy", $this->migration->prepare(true));
		self::assertSame("modern", $this->migration->prepare());
		self::assertSame($before, hash_file("sha256", $this->paths->resourcePacks() . "/Example.mcpack"));
		self::assertSame("resource_stack:\n  - Example.mcpack\n", file_get_contents($this->paths->resourcePacks() . "/resource_packs.yml"));
		self::assertFileExists($this->paths->resourcePacks() . "/Example.mcpack.key");
		self::assertDirectoryDoesNotExist($this->root . "/resource_packs");
	}

	public function testResourcePackCollisionPreservesBothCopies() : void{
		mkdir($this->root . "/resource_packs");
		file_put_contents($this->root . "/resource_packs/pack.mcpack", "legacy");
		mkdir($this->paths->resourcePacks());
		file_put_contents($this->paths->resourcePacks() . "/pack.mcpack", "modern");
		$this->expectExceptionMessage("collision");
		try{
			$this->migration->prepare(true);
		}finally{
			self::assertSame("legacy", file_get_contents($this->root . "/resource_packs/pack.mcpack"));
			self::assertSame("modern", file_get_contents($this->paths->resourcePacks() . "/pack.mcpack"));
		}
	}

	public function testInterruptedResourcePackRenameResumes() : void{
		mkdir($this->root . "/resource_packs");
		file_put_contents($this->root . "/resource_packs/pack.zip", "bytes");
		file_put_contents($this->paths->migrationJournal(), json_encode(["version" => 3, "completed" => [], "copying" => null]));
		rename($this->root . "/resource_packs", $this->paths->resourcePacks());
		self::assertSame("partial", $this->migration->prepare(true));
		self::assertSame("modern", $this->migration->prepare());
		self::assertSame("bytes", file_get_contents($this->paths->resourcePacks() . "/pack.zip"));
	}

	public function testLogsCrashesAndTimingsMigrateWithoutChangingContents() : void{
		file_put_contents($this->root . "/server.log", "historical log\n");
		mkdir($this->root . "/log_archive");
		file_put_contents($this->root . "/log_archive/old.log", "historical archive");
		mkdir($this->root . "/crashdumps");
		file_put_contents($this->root . "/crashdumps/old.txt", "historical crash");
		mkdir($this->root . "/pulse");
		file_put_contents($this->root . "/pulse/old.qpulse", "historical profile");
		mkdir($this->root . "/timings");
		file_put_contents($this->root . "/timings/old.json", "historical timings");
		self::assertSame("legacy", $this->migration->diagnose()["state"]);
		self::assertStringContainsString("timings", $this->migration->prepare(false, true));
		try{
			$this->migration->prepare();
			self::fail("Legacy diagnostics must require migration");
		}catch(\RuntimeException $e){
			self::assertStringContainsString("--migrate-legacy", $e->getMessage());
		}
		self::assertSame("legacy", $this->migration->prepare(true));
		self::assertSame("modern", $this->migration->prepare());
		self::assertSame("historical log\n", file_get_contents($this->paths->logs() . "/server.log"));
		self::assertSame("historical archive", file_get_contents($this->paths->logs() . "/archive/old.log"));
		self::assertSame("historical crash", file_get_contents($this->paths->crashes() . "/old.txt"));
		self::assertSame("historical profile", file_get_contents($this->paths->profiles() . "/old.qpulse"));
		self::assertSame("historical timings", file_get_contents($this->paths->profiles() . "/timings/old.json"));
	}

	public function testLogCollisionPreservesBothFiles() : void{
		file_put_contents($this->root . "/server.log", "legacy");
		mkdir($this->paths->logs());
		file_put_contents($this->paths->logs() . "/server.log", "modern");
		$this->expectExceptionMessage("collision");
		try{
			$this->migration->prepare(true);
		}finally{
			self::assertSame("legacy", file_get_contents($this->root . "/server.log"));
			self::assertSame("modern", file_get_contents($this->paths->logs() . "/server.log"));
		}
	}

	public function testInterruptedCrashDirectoryRenameResumes() : void{
		mkdir($this->root . "/crashdumps");
		file_put_contents($this->root . "/crashdumps/old.txt", "historical");
		mkdir(dirname($this->paths->crashes()), 0777, true);
		file_put_contents($this->paths->migrationJournal(), json_encode(["version" => 3, "completed" => [], "copying" => null]));
		rename($this->root . "/crashdumps", $this->paths->crashes());
		self::assertSame("partial", $this->migration->prepare(true));
		self::assertSame("modern", $this->migration->prepare());
		self::assertSame("historical", file_get_contents($this->paths->crashes() . "/old.txt"));
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
		self::assertSame("legacy", self::table(CoreConfig::load($this->paths->serverToml()), "server")["motd"]);
		self::assertFileDoesNotExist($this->paths->serverProperties());
		self::assertSame("modern", $this->migration->prepare());
	}

	public function testMigratedWorldAndPlayerDataLoadSaveAndRestart() : void{
		file_put_contents($this->root . "/server.properties", "level-name=Lobby\n");
		$legacyWorld = $this->root . "/worlds/Lobby";
		mkdir($legacyWorld . "/db", 0777, true);
		BedrockWorldData::generate($legacyWorld, "Lobby", WorldCreationOptions::create()->setSeed(42));
		$originalMetadata = hash_file("sha256", $legacyWorld . "/level.dat");
		mkdir($this->root . "/players");
		$legacyPlayers = new DatFilePlayerDataProvider($this->root . "/players");
		$legacyPlayers->saveData("Alice", CompoundTag::create()->setString("name", "Alice"));

		self::assertSame("legacy", $this->migration->prepare(true));
		self::assertSame("modern", $this->migration->prepare());
		self::assertSame("Lobby", self::table(CoreConfig::load($this->paths->serverToml()), "server")["level-name"]);
		$worldPath = $this->paths->worlds() . "/Lobby";
		self::assertSame($originalMetadata, hash_file("sha256", $worldPath . "/level.dat"));
		$providers = (new WorldProviderManager())->getMatchingProviders($worldPath);
		self::assertArrayHasKey("leveldb", $providers);
		$provider = $providers["leveldb"]->fromPath($worldPath, $this->createMock(\Logger::class));
		try{
			$metadata = $provider->getWorldData();
			self::assertSame("Lobby", $metadata->getName());
			self::assertSame(42, $metadata->getSeed());
			$metadata->setTime(123);
			$metadata->save();
		}finally{
			$provider->close();
		}
		self::assertSame(123, (new BedrockWorldData($worldPath . "/level.dat"))->getTime());

		$players = new DatFilePlayerDataProvider($this->paths->players());
		self::assertTrue($players->hasData("ALICE"));
		$data = $players->loadData("alice");
		self::assertNotNull($data);
		self::assertSame("Alice", $data->getString("name"));
		$data->setString("rank", "admin");
		$players->saveData("ALICE", $data);
		self::assertSame("admin", (new DatFilePlayerDataProvider($this->paths->players()))->loadData("Alice")?->getString("rank"));
		self::assertFileDoesNotExist($this->root . "/players/alice.dat");
		self::assertDirectoryDoesNotExist($this->root . "/worlds");
	}

	public function testCollisionIsRejectedBeforeMovingAnything() : void{
		file_put_contents($this->root . "/server.properties", "motd=old");
		mkdir($this->paths->config());
		file_put_contents($this->paths->serverProperties(), "new");
		$this->expectExceptionMessage("collision");
		try{
			$this->migration->prepare(true);
		}finally{
			self::assertSame("motd=old", file_get_contents($this->root . "/server.properties"));
		}
	}

	public function testResumesVerifiedCopyWithoutDeletingUnverifiedSource() : void{
		file_put_contents($this->root . "/server.properties", "motd=old");
		mkdir($this->paths->config());
		file_put_contents($this->paths->serverProperties(), "motd=wrong");
		file_put_contents($this->paths->migrationJournal(), json_encode(["version" => 1, "completed" => [], "copying" => \Symfony\Component\Filesystem\Path::join($this->root, "server.properties")]));
		try{
			$this->migration->prepare(true);
			self::fail("Expected verification failure");
		}catch(\RuntimeException $e){
			self::assertStringContainsString("differs", $e->getMessage());
		}
		self::assertSame("motd=old", file_get_contents($this->root . "/server.properties"));
		file_put_contents($this->paths->serverProperties(), "motd=old");
		self::assertSame("partial", $this->migration->prepare(true));
		self::assertFileDoesNotExist($this->root . "/server.properties");
		self::assertSame("old", self::table(CoreConfig::load($this->paths->serverToml()), "server")["motd"]);
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

	public function testChangedWorldFileDuringInterruptedRetirementIsPreserved() : void{
		mkdir($this->root . "/worlds/Lobby", 0777, true);
		file_put_contents($this->root . "/worlds/Lobby/level.dat", "newer data");
		mkdir($this->paths->worlds() . "/Lobby", 0777, true);
		file_put_contents($this->paths->worlds() . "/Lobby/level.dat", "older data");
		$digest = (new \ReflectionMethod(FilesystemMigration::class, "fingerprint"))->invoke($this->migration, $this->paths->worlds());
		file_put_contents($this->paths->migrationJournal(), json_encode(["version" => 3, "completed" => [], "copying" => \Symfony\Component\Filesystem\Path::join($this->root, "worlds"), "phase" => "retiring", "digest" => $digest]));
		try{
			$this->migration->prepare(true);
			self::fail("Changed source must not be retired");
		}catch(\RuntimeException $e){
			self::assertStringContainsString("differs", $e->getMessage());
		}
		self::assertSame("newer data", file_get_contents($this->root . "/worlds/Lobby/level.dat"));
		self::assertSame("older data", file_get_contents($this->paths->worlds() . "/Lobby/level.dat"));
	}

	public function testModernMarkerWithMissingDirectoryIsRejected() : void{
		$this->migration->prepare();
		rmdir($this->paths->worlds());
		$this->expectExceptionMessage("incomplete");
		$this->migration->prepare(true);
	}

	public function testMixedLayoutCanMigrateWithoutCollision() : void{
		file_put_contents($this->root . "/server.properties", "motd=old");
		mkdir($this->paths->config());
		file_put_contents($this->paths->config() . "/migration.ini", "[migration]\nauto_on_startup = true\n");
		self::assertSame("mixed", $this->migration->diagnose()["state"]);
		self::assertSame("mixed", $this->migration->prepare(true));
		self::assertSame("old", self::table(CoreConfig::load($this->paths->serverToml()), "server")["motd"]);
		self::assertTrue(self::table(CoreConfig::load($this->paths->serverToml()), "migration")["auto_on_startup"]);
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

	public function testStaleMigrationLockDoesNotBlockStartup() : void{
		file_put_contents($this->paths->migrationLock(), "stale pid");
		self::assertSame("empty", $this->migration->prepare());
		self::assertSame("modern", $this->migration->prepare());
	}

	public function testMigrationLockSymlinkIsRejected() : void{
		$target = $this->root . "/outside.lock";
		file_put_contents($target, "keep");
		if(!@symlink($target, $this->paths->migrationLock())){
			self::markTestSkipped("Creating symbolic links is unavailable");
		}
		$this->expectExceptionMessage("symbolic link");
		try{
			$this->migration->prepare();
		}finally{
			self::assertSame("keep", file_get_contents($target));
			self::assertFileDoesNotExist($this->paths->layoutFile());
		}
	}

	public function testLegacyConfigurationPreservesSettingsAndSnapshot() : void{
		file_put_contents($this->root . "/pocketmine.yml", "settings:\n  shutdown-message: Custom\ncustom:\n  optional: null\n  value: 42\n");
		file_put_contents($this->root . "/server.properties", "motd=My server\nserver-port=19140\ncustom-key=keep\n");
		file_put_contents($this->root . "/plugin_list.yml", "mode: whitelist\nplugins:\n  - Example\n");
		self::assertSame("legacy", $this->migration->prepare(true));
		$server = CoreConfig::load($this->paths->serverToml());
		self::assertSame("My server", self::table($server, "server")["motd"]);
		self::assertSame(19140, self::table($server, "server")["server-port"]);
		self::assertSame("keep", self::table($server, "server")["custom-key"]);
		self::assertSame("Custom", self::table($server, "settings")["shutdown-message"]);
		self::assertNull(self::table($server, "custom")["optional"]);
		self::assertSame(42, self::table($server, "custom")["value"]);
		self::assertSame(["mode" => "whitelist", "plugins" => ["Example"]], CoreConfig::load($this->paths->pluginsToml()));
		self::assertFileDoesNotExist($this->root . "/pocketmine.yml");
		self::assertFileDoesNotExist($this->paths->serverProperties());
		$backups = glob($this->paths->backups() . "/config-*");
		self::assertIsArray($backups);
		self::assertCount(1, $backups);
		self::assertSame("motd=My server\nserver-port=19140\ncustom-key=keep\n", file_get_contents($backups[0] . "/server.properties"));
		self::assertSame("modern", $this->migration->prepare());
	}

	public function testConflictingLegacySettingIsRejected() : void{
		file_put_contents($this->root . "/pocketmine.yml", "settings:\n  shutdown-message: One\n");
		file_put_contents($this->root . "/quark.yml", "settings:\n  shutdown-message: Two\n");
		$this->expectExceptionMessage("Conflicting legacy setting settings.shutdown-message");
		try{
			$this->migration->prepare(true);
		}finally{
			self::assertFileDoesNotExist($this->paths->serverToml());
			$backups = glob($this->paths->backups() . "/config-*");
			self::assertIsArray($backups);
			self::assertFileExists($backups[0] . "/quark.yml");
		}
	}

	public function testValidModernConfigurationIsNeverReplacedByLegacyMigration() : void{
		mkdir($this->paths->config());
		copy(RESOURCE_PATH . "server.toml", $this->paths->serverToml());
		$original = file_get_contents($this->paths->serverToml());
		file_put_contents($this->root . "/server.properties", "motd=Legacy\n");
		$this->expectExceptionMessage("Configuration migration collision");
		try{
			$this->migration->prepare(true);
		}finally{
			self::assertSame($original, file_get_contents($this->paths->serverToml()));
			self::assertFileExists($this->root . "/server.properties");
		}
	}

	public function testResumesAfterFirstTomlFileIsPublished() : void{
		mkdir($this->paths->config());
		mkdir($this->paths->backups());
		file_put_contents($this->paths->serverProperties(), "motd=Resume\n");
		$stage = new ConfigurationMigration($this->paths);
		$stage->begin();
		file_put_contents($this->paths->pluginsToml(), "mode = 'whitelist'\nplugins = []\n");
		try{
			$this->migration->prepare(true);
			self::fail("Expected destination collision");
		}catch(\RuntimeException $e){
			self::assertStringContainsString("collision", $e->getMessage());
		}
		self::assertFileExists($this->paths->serverToml());
		self::assertSame("partial", $this->migration->diagnose()["state"]);
		unlink($this->paths->pluginsToml());
		self::assertSame("partial", $this->migration->prepare(true));
		self::assertSame("Resume", self::table(CoreConfig::load($this->paths->serverToml()), "server")["motd"]);
		self::assertFileDoesNotExist($this->paths->serverProperties());
	}

	public function testResumesAfterVersionMarkerWithPluginDirectory() : void{
		$this->migration->prepare();
		copy(RESOURCE_PATH . "server.toml", $this->paths->serverToml());
		copy(RESOURCE_PATH . "plugins.toml", $this->paths->pluginsToml());
		mkdir($this->paths->plugins . "/ExamplePlugin");
		file_put_contents($this->paths->migrationJournal(), json_encode(["version" => 2, "completed" => [], "copying" => null]));
		self::assertSame("partial", $this->migration->prepare(true));
		self::assertSame("modern", $this->migration->prepare());
	}

	public function testDefaultTomlAndPublicConfigAccessors() : void{
		$this->migration->prepare();
		copy(RESOURCE_PATH . "server.toml", $this->paths->serverToml());
		copy(RESOURCE_PATH . "plugins.toml", $this->paths->pluginsToml());
		$config = new utils\Config($this->paths->serverToml(), utils\Config::TOML);
		self::assertSame(CoreConfig::parse(Filesystem::fileGetContents(RESOURCE_PATH . "server.toml")), require dirname(__DIR__, 2) . "/generated/CoreConfigSchema.php");
		CoreConfig::validateServer($config->getAll());
		CoreConfig::validatePlugins(CoreConfig::load($this->paths->pluginsToml()));
		$group = new ServerConfigGroup($config, $config, true);
		self::assertSame(19132, $group->getConfigInt(ServerProperties::SERVER_PORT_IPV4));
		self::assertSame("Server closed", $group->getPropertyString("settings.shutdown-message", ""));
		$group->setConfigInt(ServerProperties::SERVER_PORT_IPV4, 19140);
		$group->save();
		self::assertSame(19140, (new utils\Config($this->paths->serverToml(), utils\Config::TOML))->getNested("server.server-port"));
	}

	public function testInvalidKnownValuesIdentifyKeys() : void{
		$server = CoreConfig::parse(Filesystem::fileGetContents(RESOURCE_PATH . "server.toml"));
		$properties = self::table($server, "server");
		$properties["server-port"] = 70000;
		$server["server"] = $properties;
		$this->expectExceptionMessage("server.server-port");
		CoreConfig::validateServer($server);
	}

	public function testInvalidLoggingAndDiagnosticsLimitsIdentifyKeys() : void{
		foreach([
			["logging" => ["max-file-size" => 0]],
			["logging" => ["max-archives" => -1]],
			["diagnostics" => ["max-profiles" => -1]],
			["diagnostics" => ["max-crash-reports" => 100001]]
		] as $configuration){
			try{
				CoreConfig::validateServer($configuration);
				self::fail("Invalid retention limit was accepted");
			}catch(\InvalidArgumentException $e){
				self::assertStringContainsString(isset($configuration["logging"]) ? "logging." : "diagnostics.", $e->getMessage());
			}
		}
	}

	public function testRelatedMemoryLimitsAreValidated() : void{
		$server = CoreConfig::parse(Filesystem::fileGetContents(RESOURCE_PATH . "server.toml"));
		$memory = self::table($server, "memory");
		$memory["main-limit"] = 2048;
		$server["memory"] = $memory;
		$this->expectExceptionMessage("memory.main-limit");
		CoreConfig::validateServer($server);
	}

	public function testNullableListRoundTripsWithoutLosingIndexes() : void{
		$value = ["custom" => ["first", null, "last"]];
		self::assertSame($value, CoreConfig::parse(CoreConfig::encode($value)));
	}

	public function testNativeTomlFileParsingAndErrorReporting() : void{
		self::assertTrue(extension_loaded("toml"));
		file_put_contents($this->root . "/config.toml", "[server]\nserver-port = 19132\n");
		self::assertSame(["server" => ["server-port" => 19132]], CoreConfig::load($this->root . "/config.toml"));
		unlink($this->root . "/config.toml");
		self::assertSame(["server" => ["server-port" => 19132]], CoreConfig::load($this->root . "/config.toml"));
		file_put_contents($this->root . "/invalid.toml", "[server]\nserver-port = \n");
		try{
			CoreConfig::load($this->root . "/invalid.toml");
			self::fail("Expected invalid TOML to be rejected");
		}catch(\RuntimeException $e){
			self::assertStringContainsString("invalid.toml", $e->getMessage());
			self::assertInstanceOf(\TomlParseException::class, $e->getPrevious());
			self::assertSame(2, $e->getPrevious()->getTomlLine());
		}
	}

	public function testMissingOptionalSettingsKeepGetterDefaults() : void{
		CoreConfig::validateServer(["settings" => ["force-language" => true]]);
		$this->migration->prepare();
		file_put_contents($this->paths->serverToml(), "[settings]\nforce-language = true\n");
		$config = new utils\Config($this->paths->serverToml(), utils\Config::TOML);
		$group = new ServerConfigGroup($config, $config, true);
		self::assertSame(19132, $group->getConfigInt(ServerProperties::SERVER_PORT_IPV4, 19132));
		self::assertTrue($group->getPropertyBool("settings.force-language", false));
	}
}
