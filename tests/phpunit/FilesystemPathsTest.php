<?php

declare(strict_types=1);

namespace quark;

use Bedwire\Server as BedwireServer;
use PHPUnit\Framework\TestCase;
use quark\network\mcpe\bedwire\BedwireTransport;
use quark\utils\Config;
use quark\utils\Filesystem;
use function bin2hex;
use function dirname;
use function extension_loaded;
use function fclose;
use function file_put_contents;
use function function_exists;
use function hash_file;
use function mkdir;
use function random_bytes;
use function str_contains;
use function str_replace;
use function stream_socket_client;
use function sys_get_temp_dir;
use function unlink;
use function var_export;
use const DIRECTORY_SEPARATOR;
use const PHP_BINARY;

final class FilesystemPathsTest extends TestCase{
	private string $root;
	private FilesystemPaths $paths;

	protected function setUp() : void{
		$this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "quark-layout-" . bin2hex(random_bytes(8));
		mkdir($this->root);
		$this->paths = new FilesystemPaths($this->root, $this->root . "/plugins");
	}

	protected function tearDown() : void{
		CoreConfig::forget($this->paths->serverToml());
		Filesystem::recursiveUnlink($this->root);
	}

	/**
	 * @param array<string, mixed> $config
	 * @return array<array-key, mixed>
	 */
	private static function table(array $config, string $key) : array{
		$value = $config[$key];
		self::assertIsArray($value);
		return $value;
	}

	public function testInitializesOnlyRequiredDirectories() : void{
		$this->paths->initialize(false);
		$this->paths->initialize(false);
		foreach([$this->paths->config(), $this->paths->plugins, $this->paths->worlds(), $this->paths->players()] as $directory){
			self::assertDirectoryExists($directory);
		}
		foreach([$this->paths->pluginConfig(), $this->paths->pluginData(), $this->paths->logs(), $this->paths->backups(), $this->paths->profiles()] as $directory){
			self::assertDirectoryDoesNotExist($directory);
		}
		$this->paths->initialize(true);
		self::assertDirectoryExists($this->paths->logs());
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
		[, $output] = ProcessTestHelper::run([PHP_BINARY, "-r", $script], ready: static function(string $output, string $errors) use ($path) : bool{
			if(!str_contains($output, "\n")){ return false; }
			self::assertMatchesRegularExpression('/^[0-9]+\r?\n$/', $output, $errors);
			self::assertSame(-1, Filesystem::createLockFile($path));
			return true;
		});
		self::assertStringContainsString("\n", $output);
		self::assertNull(Filesystem::createLockFile($path));
		Filesystem::releaseLockFile($path);
	}

	public function testDefaultTomlAndPublicConfigAccessors() : void{
		$this->paths->initialize(false);
		$group = ServerConfigGroup::load($this->paths->serverToml());
		self::assertSame(CoreConfig::parse(Filesystem::fileGetContents(RESOURCE_PATH . "server.toml")), require dirname(__DIR__, 2) . "/generated/CoreConfigSchema.php");
		self::assertSame(["mode" => "blacklist", "names" => []], $group->getProperty("plugins"));
		self::assertSame(19132, $group->getConfigInt(ServerProperties::SERVER_PORT_IPV4));
		self::assertSame("Server closed", $group->getPropertyString("settings.shutdown-message", ""));
		$group->setConfigInt(ServerProperties::SERVER_PORT_IPV4, 19140);
		$group->save();
		self::assertSame(19140, (new Config($this->paths->serverToml(), Config::TOML))->getNested("network.port"));
	}

	public function testInvalidKnownValuesIdentifyKeys() : void{
		$server = CoreConfig::parse(Filesystem::fileGetContents(RESOURCE_PATH . "server.toml"));
		$properties = self::table($server, "network");
		$properties["port"] = 70000;
		$server["network"] = $properties;
		$this->expectExceptionMessage("network.port");
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
		CoreConfig::validateServer(["server" => ["force-language" => true]]);
		$this->paths->initialize(false);
		file_put_contents($this->paths->serverToml(), "[server]\nforce-language = true\n");
		$group = ServerConfigGroup::load($this->paths->serverToml());
		self::assertSame(19132, $group->getConfigInt(ServerProperties::SERVER_PORT_IPV4, 19132));
		self::assertTrue($group->getPropertyBool("settings.force-language", false));
	}

	public function testViewDistancesExampleInTheTemplateIsValid() : void{
		$template = str_replace("\r\n", "\n", Filesystem::fileGetContents(__DIR__ . "/../../resources/server.toml"));
		self::assertArrayNotHasKey("view-distances", CoreConfig::parse($template));
		$enabled = str_replace(["# [view-distances]", "# nether = 8", "# \"mini games\" = 6"], ["[view-distances]", "nether = 8", "\"mini games\" = 6"], $template);
		self::assertNotSame($template, $enabled);
		$data = CoreConfig::parse($enabled);
		CoreConfig::validateServer($data);
		self::assertSame(["nether" => 8, "mini games" => 6], $data["view-distances"]);
	}

	public function testSettersRefreshCachedSectionsAndValues() : void{
		$this->paths->initialize(false);
		ServerConfigGroup::createDefault($this->paths->serverToml());
		$group = ServerConfigGroup::load($this->paths->serverToml());
		$initial = $group->getProperty("gameplay");
		self::assertIsArray($initial);
		self::assertSame(2, $group->getConfigInt(ServerProperties::DIFFICULTY));
		$group->setConfigString(ServerProperties::GAME_MODE, "CREATIVE");
		$group->setConfigInt(ServerProperties::DIFFICULTY, 3);
		$group->setConfigBool(ServerProperties::PVP, false);
		$updated = $group->getProperty("gameplay");
		self::assertIsArray($updated);
		self::assertSame("CREATIVE", $updated["gamemode"]);
		self::assertSame(3, $updated["difficulty"]);
		self::assertFalse($updated["pvp"]);
		self::assertSame(3, $group->getConfigInt(ServerProperties::DIFFICULTY));
		$group->save();
		self::assertSame($updated, CoreConfig::load($this->paths->serverToml())["gameplay"]);
	}

	public function testBlockPhysicsIsOffByDefault() : void{
		$this->paths->initialize(false);
		$existing = "config-version = 2\n[server]\nmotd = 'kept'\n";
		file_put_contents($this->paths->serverToml(), $existing);
		self::assertSame($existing, Filesystem::fileGetContents($this->paths->serverToml()));
		$group = ServerConfigGroup::load($this->paths->serverToml());
		self::assertFalse($group->getPropertyBool(YmlServerProperties::BLOCK_PHYSICS_FAST_FALLING_BLOCKS, false));
		self::assertFalse(CoreConfig::defaultValue(YmlServerProperties::BLOCK_PHYSICS_FAST_FALLING_BLOCKS));

		file_put_contents($this->paths->serverToml(), "config-version = 2\n[block-physics]\nfast-falling-blocks = true\n");
		CoreConfig::forget($this->paths->serverToml());
		self::assertTrue(ServerConfigGroup::load($this->paths->serverToml())->getPropertyBool(YmlServerProperties::BLOCK_PHYSICS_FAST_FALLING_BLOCKS, false));
	}

	public function testBlockPhysicsRejectsNonBooleans() : void{
		$this->paths->initialize(false);
		file_put_contents($this->paths->serverToml(), "config-version = 2\n[block-physics]\nfast-falling-blocks = 1\n");
		$this->expectExceptionMessage("block-physics.fast-falling-blocks");
		ServerConfigGroup::load($this->paths->serverToml());
	}

	public function testNetherNetOptionsInitializeAndStopWithPersistentIdentity() : void{
		$this->paths->initialize(false);
		ServerConfigGroup::createDefault($this->paths->serverToml());
		$config = new Config($this->paths->serverToml(), Config::TOML);
		$config->setNested("network.bind-address", "127.0.0.1");
		$config->setNested("transport.nethernet.lan-discovery", false);
		$config->setNested("transport.nethernet.port-range", [40100, 40120]);
		$config->setNested("transport.nethernet.advertise-addresses", ["203.0.113.7"]);
		$config->setNested("transport.nethernet.ice-servers", ["stun:stun.example.com:3478", "turn:turn.example.com:3478"]);
		$config->setNested("transport.nethernet.unreliable-events", true);
		$group = new ServerConfigGroup($config);
		$server = self::createStub(Server::class);
		$server->method("getConfigGroup")->willReturn($group);
		$server->method("getIp")->willReturn("127.0.0.1");
		$server->method("getPort")->willReturn(19132);
		$server->method("getMaxPlayers")->willReturn(20);
		$server->method("getOnlineMode")->willReturn(true);
		$server->method("getTrustedProxyKeys")->willReturn([]);
		$server->method("getDataPath")->willReturn($this->root . "/");
		$options = (new \ReflectionMethod(BedwireTransport::class, "options"))->invoke(new BedwireTransport($server));
		self::assertIsArray($options);
		self::assertSame(40100, $options["port_range_begin"]);
		self::assertSame(40120, $options["port_range_end"]);
		self::assertSame(0, $options["lan_port"]);
		self::assertSame(["203.0.113.7"], $options["advertise_addresses"]);
		self::assertTrue($options["unreliable_events"]);
		$identityHash = null;
		for($i = 0; $i < 3; ++$i){
			$options["udp_mux"] = $i === 1;
			$native = new BedwireServer("127.0.0.1", 0, $options);
			try{
				self::assertTrue($native->isRunning());
				self::assertGreaterThan(0, $native->getPort());
				$connection = stream_socket_client("tcp://127.0.0.1:" . $native->getPort(), $errorCode, $errorMessage, 1);
				self::assertIsResource($connection, $errorMessage ?? "");
				fclose($connection);
				$hash = hash_file("sha256", $this->root . "/data/nethernet-identity.der");
				self::assertNotFalse($hash);
				$identityHash ??= $hash;
				self::assertSame($identityHash, $hash);
			}finally{
				$native->stop();
			}
			self::assertFalse($native->isRunning());
		}
	}
}
