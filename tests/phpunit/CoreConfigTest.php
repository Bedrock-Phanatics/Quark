<?php

declare(strict_types=1);

namespace quark;

use PHPUnit\Framework\TestCase;
use quark\utils\Utils;
use function array_keys;
use function is_array;
use function json_encode;
use function ksort;
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
