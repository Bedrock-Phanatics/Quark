<?php

declare(strict_types=1);

namespace quark;

use PHPUnit\Framework\TestCase;
use quark\utils\Config;
use quark\utils\Filesystem;
use quark\utils\Utils;
use function array_keys;
use function bin2hex;
use function file_put_contents;
use function is_array;
use function json_encode;
use function ksort;
use function random_bytes;
use function str_replace;
use function sys_get_temp_dir;
use function unlink;
use const INF;
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
