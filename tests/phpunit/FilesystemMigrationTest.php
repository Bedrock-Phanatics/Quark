<?php

declare(strict_types=1);

namespace quark;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;
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
use function random_bytes;
use function rename;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;
use const DIRECTORY_SEPARATOR;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;

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
		self::assertSame(3, $data["version"]);
		self::assertFileExists($this->paths->accessDatabase());
		self::assertDirectoryExists($this->paths->players());
		self::assertDirectoryExists($this->paths->pluginData());
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
