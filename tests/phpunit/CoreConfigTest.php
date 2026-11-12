<?php

declare(strict_types=1);

namespace quark;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use quark\plugin\PluginGraylist;
use quark\utils\Config;
use quark\utils\Filesystem;
use quark\utils\Utils;
use function array_keys;
use function bin2hex;
use function file_put_contents;
use function is_array;
use function json_encode;
use function ksort;
use function mkdir;
use function random_bytes;
use function str_replace;
use function sys_get_temp_dir;
use function unlink;
use const INF;
use const PHP_INT_MAX;
use const SORT_STRING;

final class CoreConfigTest extends TestCase{

	public function testEncodeRoundTripsKeysTomlCannotWriteBare() : void{
		$data = [
			"a.b" => 1,
			"with space" => ["x.y" => true],
			"ключ" => "unicode",
			"" => "empty key",
			"ranks" => [1 => "guest", 5 => "admin"],
			"slots" => [0 => "a", 2 => "c"],
			"kits" => [["name" => "starter", "items" => [1, 2]], ["name" => "vip", "meta" => ["tier" => 2]]],
			"text" => "line\n\"quoted\" \\ tab\t\x01",
			"floats" => [0.1, -0.0, 1e300, INF, -INF],
			"mixed" => [1, "a", ["k" => "v"], [2]],
			"nested" => ["deep" => ["deeper" => ["value" => null]]],
		];
		//TOML puts a table's plain keys before its subtables, so only key order may change
		self::assertSame(self::sorted($data), self::sorted(CoreConfig::parse(CoreConfig::encode($data))));
		self::assertSame(["one", "two"], CoreConfig::parse(CoreConfig::encode(["one", "two"])), "a top-level list survives as integer keys");
	}

	/**
	 * @phpstan-param array<array-key, mixed> $data
	 * @phpstan-return array<array-key, mixed>
	 */
	private static function sorted(array $data) : array{
		foreach(Utils::promoteKeys($data) as $key => $value){
			if(is_array($value)){
				$data[$key] = self::sorted($value);
			}
		}
		ksort($data, SORT_STRING);
		return $data;
	}

	public function testUpdateOnlyTouchesWhatChanged() : void{
		$original = <<<'TOML'
			# Server settings
			memory.limit = 0 # MiB
			memory.rate = 20

			[server]
			# Shown in the server list
			motd = 'Quark Server'
			white-list = false # who may join
			level-seed = ''

			[network]
			batch-threshold = 256

			TOML;
		/** @var array<string, array<string, mixed>> $data */
		$data = CoreConfig::parse($original);
		$data["server"]["white-list"] = true;
		$data["server"]["motd"] = "Hello \"you\"";
		$data["server"]["max-players"] = 30;
		$data["server"]["level-seed"] = null;
		$data["memory"]["check"] = true;
		unset($data["network"]["batch-threshold"]);
		$data["network"]["compression"] = "zlib";
		$data["extra"] = ["enabled" => true];
		$updated = CoreConfig::update($original, $data);
		self::assertSame(<<<'TOML'
			# Server settings
			memory.limit = 0 # MiB
			memory.rate = 20
			memory.check = true
			extra.enabled = true
			__quark_nulls = ["[\"server\",\"level-seed\"]"]

			[server]
			# Shown in the server list
			motd = "Hello \"you\""
			white-list = true # who may join
			max-players = 30

			[network]
			compression = "zlib"

			TOML, $updated);
		self::assertSame(self::sorted($data), self::sorted(CoreConfig::parse($updated)));
		self::assertSame($updated, CoreConfig::update($updated, $data), "saving unchanged data leaves the file alone");
	}

	public function testUpdateKeepsLineEndingsAndRewritesChangedTableArrays() : void{
		$original = "# kits\r\nlimit = 1\r\n\r\n[[kits]]\r\nname = \"starter\"\r\n";
		/** @var array{limit: int, new?: string, kits: list<array<string, string>>} $data */
		$data = CoreConfig::parse($original);
		$data["limit"] = 2;
		$data["new"] = "x";
		self::assertSame("# kits\r\nlimit = 2\r\nnew = \"x\"\r\n\r\n[[kits]]\r\nname = \"starter\"\r\n", CoreConfig::update($original, $data), "untouched table arrays keep the rest of the file");

		$data["kits"][] = ["name" => "vip"];
		$updated = CoreConfig::update($original, $data);
		self::assertSame(self::sorted($data), self::sorted(CoreConfig::parse($updated)));
		self::assertSame(CoreConfig::encode($data), $updated, "a changed table array is written in full");
	}

	public function testUpdateKeepsCommentsBesideNan() : void{
		$original = "# keep\ncustom = nan\nvalue = 1\n";
		$data = CoreConfig::parse($original);
		$data["value"] = 2;
		self::assertSame("# keep\ncustom = nan\nvalue = 2\n", CoreConfig::update($original, $data));
	}

	public function testTomlSaveRejectsStaleValuesAndReloadsChanges() : void{
		$path = sys_get_temp_dir() . "/quark-config-" . bin2hex(random_bytes(6)) . ".toml";
		file_put_contents($path, "value = 1\n");
		try{
			$config = new Config($path);
			file_put_contents($path, "value = 2\n");
			self::assertSame(2, (new Config($path))->get("value"));
			$config->set("other", 3);
			try{
				$config->save();
				self::fail("Save overwrote a newer configuration");
			}catch(\RuntimeException $e){
				self::assertStringContainsString("changed since it was loaded", $e->getMessage());
			}
			self::assertSame("value = 2\n", Filesystem::fileGetContents($path));
			$config->reload();
			$config->set("other", 3);
			$config->save();
			self::assertSame(["value" => 2, "other" => 3], (new Config($path))->getAll());
		}finally{
			@unlink($path);
		}
	}

	public function testYamlConversionRejectsMultipleDocumentsAndRecursiveAliases() : void{
		foreach(["value: 1\n---\nvalue: 2\n", "loop: &loop\n  child: *loop\n"] as $yaml){
			try{
				ConfigurationMigration::yamlToToml($yaml, "config.yml");
				self::fail("Unsafe YAML was converted");
			}catch(\RuntimeException $e){
				self::assertStringContainsString("config.yml", $e->getMessage());
			}
		}
	}

	public function testYamlConversionPreservesAConfigCreatedDuringRendering() : void{
		$directory = sys_get_temp_dir() . "/quark-config-" . bin2hex(random_bytes(6));
		mkdir($directory);
		$yaml = $directory . "/config.yml";
		$toml = $directory . "/config.toml";
		file_put_contents($yaml, "value: 1\n");
		try{
			$this->expectExceptionMessage("Configuration changed before replacement");
			ConfigurationMigration::convertYamlFile($yaml, $toml, $directory . "/backups", static function(array $data) use ($toml) : string{
				file_put_contents($toml, "value = 2\n");
				return CoreConfig::encode($data);
			});
		}finally{
			try{
				self::assertSame("value: 1\n", Filesystem::fileGetContents($yaml));
				self::assertSame("value = 2\n", Filesystem::fileGetContents($toml));
			}finally{
				Filesystem::recursiveUnlink($directory);
			}
		}
	}

	public function testServerConfigSaveKeepsComments() : void{
		$path = sys_get_temp_dir() . "/quark-config-" . bin2hex(random_bytes(6)) . ".toml";
		$template = Filesystem::fileGetContents(RESOURCE_PATH . "/server.toml");
		file_put_contents($path, $template);
		try{
			$config = new Config($path, Config::TOML);
			$group = new ServerConfigGroup($config, $config, true);
			$group->setConfigBool(ServerProperties::WHITELIST, true);
			$group->save();
			$saved = Filesystem::fileGetContents($path);
			self::assertSame(str_replace("white-list = false", "white-list = true", $template), $saved);
			$server = CoreConfig::parse($saved)["server"];
			self::assertIsArray($server);
			self::assertTrue($server["white-list"]);
		}finally{
			@unlink($path);
		}
	}

	public function testCliOverridesValidateAndKeepSavedSecurityDefaults() : void{
		$path = sys_get_temp_dir() . "/quark-config-" . bin2hex(random_bytes(6)) . ".toml";
		$template = Filesystem::fileGetContents(RESOURCE_PATH . "/server.toml");
		file_put_contents($path, $template);
		$argv = $_SERVER["argv"] ?? [];
		try{
			$_SERVER["argv"] = ["quark", "--plugins=/custom", "--xbox-auth=true", "--server.xbox-auth=false", "--network.enable-encryption=false"];
			$group = ServerConfigGroup::load($path);
			self::assertFalse($group->getConfigBool(ServerProperties::XBOX_AUTH, true));
			self::assertFalse($group->getPropertyBool(YmlServerProperties::NETWORK_ENABLE_ENCRYPTION, true));
			$group->save();
			self::assertSame($template, Filesystem::fileGetContents($path));

			$_SERVER["argv"] = ["quark", "--xbox-auth=typo"];
			$this->expectExceptionMessage("--xbox-auth");
			ServerConfigGroup::load($path);
		}finally{
			$_SERVER["argv"] = $argv;
			CoreConfig::forget($path);
			@unlink($path);
		}
	}

	/** @return iterable<string, array{array<string, mixed>}> */
	public static function invalidServerSettings() : iterable{
		yield "config-version" => [["config-version" => CoreConfig::SERVER_VERSION + 1]];
		yield "server.xbox-auth" => [["server" => ["xbox-auth" => "false"]]];
		yield "server.max-players" => [["server" => ["max-players" => 0x100000000]]];
		yield "server.gamemode" => [["server" => ["gamemode" => "SPECTATE"]]];
		yield "network.enable-encryption" => [["network" => ["enable-encryption" => null]]];
		yield "network.trusted-proxy-keys" => [["network" => ["trusted-proxy-keys" => [42]]]];
		yield "network.batch-threshold" => [["network" => ["batch-threshold" => -2]]];
		yield "network.max-mtu-size" => [["network" => ["max-mtu-size" => 399]]];
		yield "transport.nethernet.port-range" => [["transport" => ["nethernet" => ["port-range" => [30000, 1]]]]];
		yield "memory.main-limit" => [["memory" => ["main-limit" => 2048]]];
		yield "ticks-per.autosave" => [["ticks-per" => ["autosave" => 0]]];
		yield "chunk-sending.spawn-radius" => [["chunk-sending" => ["spawn-radius" => PHP_INT_MAX]]];
		yield "worlds.lobby.generator" => [["worlds" => ["lobby" => ["generator" => true]]]];
		yield "aliases.home" => [["aliases" => ["home" => ["target" => "spawn"]]]];
	}

	/** @param array<string, mixed> $data */
	#[DataProvider("invalidServerSettings")]
	public function testInvalidServerSettings(array $data) : void{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage((string) $this->dataName());
		CoreConfig::validateServer($data);
	}

	public function testNetherNetMuxCannotReserveTheQueryPort() : void{
		$nethernet = ["port-range" => [19132, 19132], "ice-udp-mux" => true];
		CoreConfig::validateServer(["transport" => ["name" => "raknet", "nethernet" => $nethernet]]);
		CoreConfig::validateServer(["transport" => ["nethernet" => $nethernet]], ["server" => ["enable-query" => false]]);
		$this->expectExceptionMessage("already used by Query");
		CoreConfig::validateServer(["transport" => ["nethernet" => $nethernet]]);
	}

	public function testNetherNetAdvertiseAddressesAcceptPublicAndPrivateLiterals() : void{
		$addresses = ["203.0.113.7", "192.168.1.20", "2001:db8::7"];
		CoreConfig::validateServer(["transport" => ["nethernet" => ["advertise-addresses" => $addresses]]]);
		self::assertSame($addresses, CoreConfig::validateNetherNetAdvertiseAddresses($addresses));
		self::assertSame([], CoreConfig::validateNetherNetAdvertiseAddresses([]));
	}

	/** @return iterable<string, array{mixed}> */
	public static function invalidAdvertiseAddresses() : iterable{
		foreach(["203.0.113.7:19132", "example.com", "[2001:db8::7]", "0.0.0.0", "224.0.0.1", "255.255.255.255", "169.254.1.1", "::", "ff02::1", "fe80::1", "::ffff:203.0.113.7", ""] as $address){
			yield "'$address'" => [[$address]];
		}
		yield "int" => [[7]];
		yield "duplicate" => [["2001:db8::7", "2001:DB8:0::7"]];
		yield "string" => ["203.0.113.7"];
		yield "map" => [["public" => "203.0.113.7"]];
		yield "too many" => [array_map(static fn(int $i) : string => "203.0.113.$i", range(1, 9))];
	}

	#[DataProvider("invalidAdvertiseAddresses")]
	public function testInvalidNetherNetAdvertiseAddresses(mixed $addresses) : void{
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage("transport.nethernet.advertise-addresses");
		CoreConfig::validateServer(["transport" => ["nethernet" => ["advertise-addresses" => $addresses]]]);
	}

	public function testServerValidationAllowsUnknownAndOmittedSettings() : void{
		CoreConfig::validateServer(["server" => ["custom" => ["value" => 1]], "custom" => ["null" => null]]);
		$this->addToAssertionCount(1);
	}

	public function testPluginGraylistRejectsAPluginTable() : void{
		$this->expectExceptionMessage("expected a list of plugin names");
		PluginGraylist::fromArray(["mode" => "whitelist", "plugins" => ["first" => "Example"]]);
	}

	public function testResourcePackPolicyRejectsAStringBoolean() : void{
		$this->expectExceptionMessage("force_resources");
		CoreConfig::validateResourcePacks(CoreConfig::parse("force_resources = 'false'"));
	}

	public function testEncodeRejectsWhatTomlCannotHold() : void{
		foreach([["__quark_nulls" => 1], ["bad" => "\xff"], ["object" => new \stdClass()]] as $data){
			try{
				CoreConfig::encode($data);
				self::fail("Encoded " . json_encode(array_keys($data)));
			}catch(\InvalidArgumentException){
			}
		}
		$this->addToAssertionCount(1);
	}
}
