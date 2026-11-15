<?php

declare(strict_types=1);

namespace quark;

use Bedwire\Server as BedwireServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use quark\network\mcpe\bedwire\BedwireTransport;
use quark\utils\Config;
use quark\utils\Filesystem;
use raklib\server\ServerSocket;
use raklib\utils\InternetAddress;
use function array_fill;
use function array_key_exists;
use function bin2hex;
use function fclose;
use function file_put_contents;
use function hash_file;
use function mkdir;
use function random_bytes;
use function str_repeat;
use function str_replace;
use function stream_socket_client;
use function sys_get_temp_dir;

final class ConfigUpgradeTest extends TestCase{
	private string $root;
	private FilesystemPaths $paths;
	private ConfigurationMigration $migration;

	protected function setUp() : void{
		$this->root = sys_get_temp_dir() . "/quark-config-upgrade-" . bin2hex(random_bytes(6));
		mkdir($this->root);
		$this->paths = new FilesystemPaths($this->root, $this->root . "/plugins");
		mkdir($this->paths->config());
		$this->migration = new ConfigurationMigration($this->paths);
	}

	protected function tearDown() : void{
		CoreConfig::forget($this->paths->serverToml());
		Filesystem::recursiveUnlink($this->root);
	}

	public function testFreshGenerationAndEveryVersionOneDefault() : void{
		ConfigurationMigration::createServer($this->paths->serverToml());
		$fresh = CoreConfig::load($this->paths->serverToml());
		self::assertSame(CoreConfig::defaults(), $fresh);
		CoreConfig::validateServer($fresh);
		self::assertTrue(CoreConfig::equivalent($fresh, CoreConfig::parse(CoreConfig::encode($fresh))));

		file_put_contents($this->paths->serverToml(), Filesystem::fileGetContents(__DIR__ . "/fixtures/server-v1.toml"));
		CoreConfig::forget($this->paths->serverToml());
		$this->migration->upgradeServer();
		self::assertTrue(CoreConfig::equivalent($fresh, CoreConfig::load($this->paths->serverToml())), "cleanup must preserve every shipped default");
		$upgraded = Filesystem::fileGetContents($this->paths->serverToml());
		$this->migration->upgradeServer();
		self::assertSame($upgraded, Filesystem::fileGetContents($this->paths->serverToml()));
	}

	public function testCustomValuesConflictsAndRemovedKeysSurviveRestart() : void{
		file_put_contents($this->paths->serverToml(), <<<'TOML'
			# Keep the operator's notes.
			config-version = 1
			[server]
			motd = 'Custom' # Keep this comment.
			server-port = 19140
			xbox-auth = false
			white-list = true
			level-seed = '001'
			view-distance = 12
			[view-distances]
			nether = 8
			"mini games" = 6
			"my.world" = 5
			[settings]
			async-workers = 3
			custom = ['keep', 'these']
			[network]
			port = 19141
			xbox-auth = true
			async-compression = true
			[network.compression]
			async = false
			[chunk-ticking]
			tick-radius = 7
			[chunk-generation]
			population-queue-size = 48
			[memory.max-chunks]
			chunk-radius = 0
			[migration]
			auto_on_startup = true
			auto-on-startup = false
			[transport.nethernet]
			key-file = 'persistent.der'
			lan-discovery = false
			ice-udp-mux = true
			port-range = [30100, 30110]
			advertise-addresses = ['203.0.113.7']
			ice-servers = ['stun:stun.example.com:3478', 'turn:turn.example.com:3478']
			unreliable-events = true
			mtu = 1200
			builtin-signaling = { enabled = true, port = 0, reverse-proxy = { enabled = true, header = 'X-Forwarded-For' } }
			[aliases]
			halt = ['stop']
			[plugins]
			legacy-data-dir = false
			[worlds.lobby]
			seed = 404
			generator = 'FLAT'
			[custom]
			value = { one = 1, two = [2, 3] }
			TOML);
		$this->migration->upgradeServer();
		$config = new Config($this->paths->serverToml(), Config::TOML);
		foreach([
			"network.port" => 19141, "network.xbox-auth" => true,
			"network.compression.async" => false, "server.whitelist" => true,
			"world.seed" => "001", "performance.async-workers" => 3, "server.view-distance" => 12,
			"chunks.tick-radius" => 7, "chunks.population-queue-size" => 48,
			"memory.low-memory-view-distance" => 0, "migration.auto-on-startup" => false,
			"settings.custom" => ["keep", "these"], "worlds.lobby.seed" => 404,
			"custom.value" => ["one" => 1, "two" => [2, 3]],
			"transport.nethernet.key-file" => "persistent.der",
			"transport.nethernet.lan-discovery" => false,
			"transport.nethernet.ice-udp-mux" => true,
			"transport.nethernet.port-range" => [30100, 30110],
			"transport.nethernet.advertise-addresses" => ["203.0.113.7"],
			"transport.nethernet.ice-servers" => ["stun:stun.example.com:3478", "turn:turn.example.com:3478"],
			"transport.nethernet.unreliable-events" => true,
		] as $path => $expected){
			self::assertSame($expected, $config->getNested($path), $path);
		}
		foreach(CoreConfig::RENAMED_SETTINGS as $old => $new){
			self::assertNull($config->getNested($old), $old);
		}
		self::assertSame(["halt" => ["stop"]], $config->getNested("aliases"));
		self::assertSame(["nether" => 8, "mini games" => 6, "my.world" => 5], $config->getNested("view-distances"));
		self::assertFalse(array_key_exists("plugins", $config->getAll()));
		self::assertNull($config->getNested("transport.nethernet.mtu"));
		self::assertNull($config->getNested("transport.nethernet.builtin-signaling"));
		$upgraded = Filesystem::fileGetContents($this->paths->serverToml());
		self::assertStringContainsString("# Keep the operator's notes.", $upgraded);
		self::assertStringContainsString("motd = 'Custom' # Keep this comment.", $upgraded);
		CoreConfig::forget($this->paths->serverToml());
		$this->migration->upgradeServer();
		self::assertSame($upgraded, Filesystem::fileGetContents($this->paths->serverToml()));
		$group = ServerConfigGroup::load($this->paths->serverToml());
		self::assertSame(19141, $group->getConfigInt(ServerProperties::SERVER_PORT_IPV4));
		self::assertSame(3, $group->getPropertyInt("settings.async-workers", 0));
	}

	public function testSparseVersionOneKeepsDefaultsAndSettersInvalidateCachedValues() : void{
		file_put_contents($this->paths->serverToml(), "config-version = 1\n");
		$this->migration->upgradeServer();
		self::assertTrue(CoreConfig::equivalent(CoreConfig::defaults(), CoreConfig::load($this->paths->serverToml())));
		file_put_contents($this->paths->serverToml(), "config-version = 2\n");
		CoreConfig::forget($this->paths->serverToml());
		$group = ServerConfigGroup::load($this->paths->serverToml());
		self::assertSame(19132, $group->getConfigInt(ServerProperties::SERVER_PORT_IPV4));
		self::assertSame(32, $group->getPropertyInt(YmlServerProperties::CHUNKS_POPULATION_QUEUE_SIZE, 2));
		$group->setConfigInt(ServerProperties::SERVER_PORT_IPV4, 19140);
		self::assertSame(19140, $group->getConfigInt(ServerProperties::SERVER_PORT_IPV4));
		$group->save();
		self::assertSame(19140, (new Config($this->paths->serverToml(), Config::TOML))->getNested("network.port"));
	}

	public function testLegacyAndNewCliOverridesDoNotRewriteTheFile() : void{
		ConfigurationMigration::createServer($this->paths->serverToml());
		$original = Filesystem::fileGetContents($this->paths->serverToml());
		$argv = $_SERVER["argv"] ?? [];
		try{
			$_SERVER["argv"] = ["quark", "--network.port=19140", "--server-port=19141", "--network.port=19142", "--network.xbox-auth=true", "--xbox-auth=false", "--network.xbox-auth=true", "--network.compression.level=invalid", "--network.compression-level=7", "--settings.async-workers=2", "--performance.async-workers=3", "--white-list=true", "--server.whitelist=false", "--level-name=legacy", "--world.name=modern", "--server.motd=Custom", "--network.compression.threshold=-1"];
			$group = ServerConfigGroup::load($this->paths->serverToml());
			self::assertSame(19142, $group->getConfigInt(ServerProperties::SERVER_PORT_IPV4));
			self::assertTrue($group->getConfigBool(ServerProperties::XBOX_AUTH));
			self::assertSame(3, $group->getPropertyInt(YmlServerProperties::PERFORMANCE_ASYNC_WORKERS, 0));
			self::assertFalse($group->getConfigBool(ServerProperties::WHITELIST));
			self::assertSame("modern", $group->getConfigString(ServerProperties::DEFAULT_WORLD_NAME));
			self::assertSame("Custom", $group->getConfigString(ServerProperties::MOTD));
			self::assertSame(-1, $group->getPropertyInt(YmlServerProperties::NETWORK_COMPRESSION_THRESHOLD, 0));
			self::assertSame(7, $group->getPropertyInt(YmlServerProperties::NETWORK_COMPRESSION_LEVEL, 0));
			$group->save();
			self::assertSame($original, Filesystem::fileGetContents($this->paths->serverToml()));
		}finally{
			$_SERVER["argv"] = $argv;
		}
	}

	public function testSettersRefreshCachedSectionsAndValues() : void{
		ConfigurationMigration::createServer($this->paths->serverToml());
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

	public function testDisabledLegacyTickingStaysDisabledUnlessCanonicalRadiusOverridesIt() : void{
		foreach([0, 7] as $radius){
			$content = "[chunk-ticking]\nper-tick = 0\ntick-radius = 3\n";
			if($radius !== 0){
				$content .= "[chunks]\ntick-radius = $radius\n";
			}
			file_put_contents($this->paths->serverToml(), $content);
			CoreConfig::forget($this->paths->serverToml());
			$this->migration->upgradeServer();
			self::assertSame($radius, (new Config($this->paths->serverToml(), Config::TOML))->getNested("chunks.tick-radius"));
		}
	}

	public function testCurrentConfigRejectsUnsupportedColocatedPluginData() : void{
		file_put_contents($this->paths->serverToml(), "config-version = 2\n[plugins]\nlegacy-data-dir = true\n");
		$this->expectExceptionMessage("plugins.legacy-data-dir");
		ServerConfigGroup::load($this->paths->serverToml());
	}

	public function testRakNetBindHostnamesSurviveMigration() : void{
		file_put_contents($this->paths->serverToml(), "config-version = 1\n[server]\nserver-ip = 'localhost'\nserver-ipv6 = 'localhost'\n[transport]\nname = 'raknet'\n");
		$this->migration->upgradeServer();
		$group = ServerConfigGroup::load($this->paths->serverToml());
		self::assertSame("localhost", $group->getConfigString(ServerProperties::SERVER_IPV6));
		$socket = new ServerSocket(new InternetAddress($group->getConfigString(ServerProperties::SERVER_IPV4), 0, 4));
		try{
			self::assertSame("localhost", $socket->getBindAddress()->getIp());
		}finally{
			$socket->close();
		}
	}

	/** @return iterable<string, array{array<string, mixed>}> */
	public static function invalidUpgradeSettings() : iterable{
		yield "config-version" => [["config-version" => null]];
		yield "memory" => [["config-version" => 0, "memory" => [123]]];
		yield "tnt-limits" => [["config-version" => 0, "tnt-limits" => [123]]];
		yield "chunk-generation" => [["config-version" => 0, "chunk-generation" => [123]]];
		yield "network" => [["config-version" => 0, "network" => [123]]];
		yield "gameplay.difficulty" => [["server" => ["difficulty" => 9]]];
		yield "network.port" => [["server" => ["server-port" => -1]]];
		yield "network.bind-address" => [["server" => ["server-ip" => 123]]];
		yield "chunks" => [["chunks" => false, "chunk-ticking" => ["tick-radius" => 5]]];
		yield "transport.nethernet.key-file" => [["transport" => ["nethernet" => ["key-file" => ""]]]];
		yield "transport.nethernet.ice-servers" => [["transport" => ["nethernet" => ["ice-servers" => array_fill(0, 33, "stun:example.com")]]]];
		yield "transport.nethernet.ice-servers size" => [["transport" => ["nethernet" => ["ice-servers" => [str_repeat("x", 513)]]]]];
		yield "transport.nethernet.ice-servers NUL" => [["transport" => ["nethernet" => ["ice-servers" => ["stun:example.com\0"]]]]];
	}

	/** @param array<string, mixed> $data */
	#[DataProvider("invalidUpgradeSettings")]
	public function testInvalidUpgradeLeavesOriginalUntouched(array $data) : void{
		$original = CoreConfig::encode($data + ["config-version" => 1]);
		file_put_contents($this->paths->serverToml(), $original);
		try{
			$this->migration->upgradeServer();
			self::fail("Invalid configuration was upgraded");
		}catch(\InvalidArgumentException $e){
			self::assertStringContainsString(str_replace([" size", " NUL"], "", (string) $this->dataName()), $e->getMessage());
		}
		self::assertSame($original, Filesystem::fileGetContents($this->paths->serverToml()));
	}

	public function testNetherNetOptionsInitializeAndStopWithPersistentIdentity() : void{
		ConfigurationMigration::createServer($this->paths->serverToml());
		$config = new Config($this->paths->serverToml(), Config::TOML);
		$config->setNested("network.bind-address", "127.0.0.1");
		$config->setNested("transport.nethernet.lan-discovery", false);
		$config->setNested("transport.nethernet.port-range", [40100, 40120]);
		$config->setNested("transport.nethernet.advertise-addresses", ["203.0.113.7"]);
		$config->setNested("transport.nethernet.ice-servers", ["stun:stun.example.com:3478", "turn:turn.example.com:3478"]);
		$config->setNested("transport.nethernet.unreliable-events", true);
		$group = new ServerConfigGroup($config, $config, true);
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
				$hash = hash_file("sha256", $this->root . "/nethernet-identity.der");
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
