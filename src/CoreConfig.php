<?php

declare(strict_types=1);

namespace quark;

use quark\plugin\PluginGraylist;
use quark\utils\Utils;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function get_debug_type;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_infinite;
use function is_int;
use function is_nan;
use function is_string;
use function json_decode;
use function json_encode;
use function ltrim;
use function mb_check_encoding;
use function ord;
use function preg_match;
use function preg_replace_callback;
use function sprintf;
use function toml_parse;
use function toml_parse_file;
use function var_export;
use const JSON_THROW_ON_ERROR;

final class CoreConfig{
	/** @var array<string, array<string, mixed>> */
	private static array $loaded = [];
	/** @var array<string, mixed>|null */
	private static ?array $defaults = null;

	/** @return array<string, mixed> */
	public static function load(string $path) : array{
		if(!isset(self::$loaded[$path])){
			try{
				self::$loaded[$path] = self::restoreNulls(toml_parse_file($path));
			}catch(\TomlParseException|\InvalidArgumentException $e){
				throw new \RuntimeException("Invalid TOML in $path: " . $e->getMessage(), 0, $e);
			}catch(\TomlException $e){
				throw new \RuntimeException("Cannot read configuration: $path: " . $e->getMessage(), 0, $e);
			}
		}
		return self::$loaded[$path];
	}

	public static function forget(string $path) : void{
		unset(self::$loaded[$path]);
	}

	/** @return array<string, mixed> */
	public static function parse(string $content) : array{
		return self::restoreNulls(toml_parse($content));
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	private static function restoreNulls(array $data) : array{
		$nulls = $data["__quark_nulls"] ?? [];
		unset($data["__quark_nulls"]);
		if(!is_array($nulls) || !array_is_list($nulls)){
			throw new \InvalidArgumentException("__quark_nulls must be a list");
		}
		foreach($nulls as $path){
			$keys = is_string($path) ? json_decode($path, true) : null;
			if(!is_array($keys) || $keys === []){
				throw new \InvalidArgumentException("Invalid __quark_nulls entry");
			}
			$node = &$data;
			foreach($keys as $key){
				if((!is_string($key) && !is_int($key)) || !is_array($node)){
					throw new \InvalidArgumentException("Invalid __quark_nulls path: $path");
				}
				if(!array_key_exists($key, $node)){
					$node[$key] = [];
				}
				$node = &$node[$key];
			}
			if($node !== []){
				throw new \InvalidArgumentException("Conflicting __quark_nulls path: $path");
			}
			$node = null;
			unset($node);
		}
		return $data;
	}

	/** @param array<array-key, mixed> $data */
	public static function encode(array $data) : string{
		if(array_key_exists("__quark_nulls", $data)){
			throw new \InvalidArgumentException("Reserved configuration key: __quark_nulls");
		}
		$nulls = [];
		self::extractNulls($data, [], $nulls);
		if($nulls !== []){
			$data["__quark_nulls"] = $nulls;
		}
		$out = "";
		self::encodeTable($data, [], $out);
		return ltrim($out, "\n");
	}

	/**
	 * Writes a table's own keys, then its subtables under headers, since TOML assigns keys to the last header seen.
	 *
	 * @phpstan-param array<array-key, mixed> $table
	 * @phpstan-param list<string> $path the encoded keys leading to this table
	 */
	private static function encodeTable(array $table, array $path, string &$out) : void{
		$subtables = [];
		foreach(Utils::promoteKeys($table) as $key => $value){
			if(is_array($value) && $value !== [] && (!array_is_list($value) || self::isTableList($value))){
				$subtables[$key] = $value;
			}else{
				$out .= self::encodeKey($key) . " = " . self::encodeValue($value) . "\n";
			}
		}
		foreach(Utils::promoteKeys($subtables) as $key => $value){
			$subpath = [...$path, self::encodeKey($key)];
			$header = implode(".", $subpath);
			if(array_is_list($value)){
				foreach($value as $element){
					$out .= "\n[[$header]]\n";
					self::encodeTable($element, $subpath, $out);
				}
			}else{
				$out .= "\n[$header]\n";
				self::encodeTable($value, $subpath, $out);
			}
		}
	}

	/** @phpstan-param list<mixed> $list */
	private static function isTableList(array $list) : bool{
		foreach($list as $element){
			if(!is_array($element) || $element === [] || array_is_list($element)){
				return false;
			}
		}
		return true;
	}

	/**
	 * Integer keys are written as strings, which PHP turns back into integer keys when the file is read.
	 */
	private static function encodeKey(int|string $key) : string{
		$key = (string) $key;
		return preg_match('/^[A-Za-z0-9_-]+$/D', $key) === 1 ? $key : self::encodeString($key);
	}

	private static function encodeValue(mixed $value) : string{
		if(is_bool($value)){
			return $value ? "true" : "false";
		}
		if(is_int($value)){
			return (string) $value;
		}
		if(is_float($value)){
			return match(true){
				is_nan($value) => "nan",
				is_infinite($value) => $value > 0 ? "inf" : "-inf",
				default => var_export($value, true)
			};
		}
		if(is_string($value)){
			return self::encodeString($value);
		}
		if(is_array($value)){
			if(array_is_list($value)){
				return "[" . implode(", ", array_map(self::encodeValue(...), $value)) . "]";
			}
			$entries = [];
			foreach(Utils::promoteKeys($value) as $key => $element){
				$entries[] = self::encodeKey($key) . " = " . self::encodeValue($element);
			}
			return "{ " . implode(", ", $entries) . " }";
		}
		throw new \InvalidArgumentException("Cannot write " . get_debug_type($value) . " to TOML");
	}

	private static function encodeString(string $value) : string{
		if(!mb_check_encoding($value, "UTF-8")){
			throw new \InvalidArgumentException("TOML strings must be valid UTF-8");
		}
		return '"' . preg_replace_callback('/[\x00-\x1f\x7f"\\\\]/', static fn(array $match) : string => match($match[0]){
			'"' => '\\"',
			'\\' => '\\\\',
			"\n" => '\\n',
			"\r" => '\\r',
			"\t" => '\\t',
			"\x08" => '\\b',
			"\x0c" => '\\f',
			default => sprintf('\\u%04X', ord($match[0]))
		}, $value) . '"';
	}

	/**
	 * @phpstan-param array<mixed, mixed> $data
	 * @phpstan-param list<string|int> $path
	 * @phpstan-param list<string> $nulls
	 */
	private static function extractNulls(array &$data, array $path, array &$nulls) : void{
		$list = array_is_list($data);
		foreach(array_keys($data) as $key){
			$value = $data[$key];
			$child = [...$path, $key];
			if($value === null){
				$nulls[] = json_encode($child, JSON_THROW_ON_ERROR);
				if($list){
					$data[$key] = [];
				}else{
					unset($data[$key]);
				}
			}elseif(is_array($value)){
				self::extractNulls($value, $child, $nulls);
				$data[$key] = $value;
			}
		}
	}

	/** @param array<string, mixed> $data */
	public static function validateServer(array $data) : void{
		$properties = $data["server"] ?? [];
		if(!is_array($properties) || $properties !== [] && array_is_list($properties)){
			throw new \InvalidArgumentException("server must be a table in config/server.toml");
		}
		foreach(Utils::promoteKeys($properties) as $key => $value){
			if(!is_string($key) || !(is_string($value) || is_int($value) || is_bool($value))){
				throw new \InvalidArgumentException("Invalid server.$key: expected string, integer, or boolean");
			}
		}
		$defaults = self::$defaults ??= require __DIR__ . "/../generated/CoreConfigSchema.php";
		foreach($defaults["server"] as $key => $expected){
			if(array_key_exists($key, $properties) && get_debug_type($properties[$key]) !== get_debug_type($expected)){
				throw new \InvalidArgumentException("Invalid server.$key: expected " . get_debug_type($expected));
			}
		}
		foreach(["server-ip", "server-ipv6"] as $key){
			if(isset($properties[$key]) && !is_string($properties[$key])){
				throw new \InvalidArgumentException("Invalid server.$key: expected string");
			}
		}
		foreach(["server-port", "server-portv6"] as $key){
			if(isset($properties[$key]) && (!is_int($properties[$key]) || $properties[$key] < 1 || $properties[$key] > 65535)){
				throw new \InvalidArgumentException("Invalid server.$key: expected port 1..65535");
			}
		}
		foreach(["max-players", "view-distance"] as $key){
			if(isset($properties[$key]) && (!is_int($properties[$key]) || $properties[$key] < 1)){
				throw new \InvalidArgumentException("Invalid server.$key: expected positive integer");
			}
		}
		if(isset($properties["difficulty"]) && (!is_int($properties["difficulty"]) || $properties["difficulty"] < 0 || $properties["difficulty"] > 3)){
			throw new \InvalidArgumentException("Invalid server.difficulty: expected 0..3");
		}
		foreach(["enable-ipv6", "white-list", "force-gamemode", "hardcore", "pvp", "enable-query", "auto-save", "xbox-auth"] as $key){
			if(isset($properties[$key]) && !is_bool($properties[$key])){
				throw new \InvalidArgumentException("Invalid server.$key: expected boolean");
			}
		}
		$migration = $data["migration"] ?? [];
		if(!is_array($migration) || !is_bool($migration["auto_on_startup"] ?? false)){
			throw new \InvalidArgumentException("Invalid migration.auto_on_startup: expected boolean");
		}
		$logging = array_key_exists("logging", $data) ? $data["logging"] : [];
		if(!is_array($logging)){ throw new \InvalidArgumentException("Invalid logging: expected table"); }
		new log\LoggingConfig($logging);
		$diagnostics = $data["diagnostics"] ?? [];
		if(!is_array($diagnostics)){
			throw new \InvalidArgumentException("Invalid diagnostics: expected table");
		}
		foreach(["logging.max-archives" => $logging["max-archives"] ?? null, "diagnostics.max-crash-reports" => $diagnostics["max-crash-reports"] ?? null, "diagnostics.max-profiles" => $diagnostics["max-profiles"] ?? null] as $key => $limit){
			if($limit !== null && (!is_int($limit) || $limit < 0 || $limit > 100000)){
				throw new \InvalidArgumentException("Invalid $key: expected integer from 0 to 100000");
			}
		}
		foreach(Utils::stringifyKeys($data) as $key => $value){
			if($key !== "server" && $key !== "migration" && array_key_exists($key, $defaults)){
				self::validateShape($value, $defaults[$key], $key);
			}
		}
		$memory = $data["memory"] ?? [];
		if(is_array($memory) && is_int($memory["main-limit"] ?? null) && is_int($memory["main-hard-limit"] ?? null) && $memory["main-hard-limit"] > 0 && $memory["main-limit"] > $memory["main-hard-limit"]){
			throw new \InvalidArgumentException("Invalid memory.main-limit: exceeds memory.main-hard-limit");
		}
	}

	private static function validateShape(mixed $value, mixed $expected, string $path) : void{
		if($expected === null || $value === null){
			return;
		}
		if($path === "settings.async-workers" && (is_int($value) && $value > 0 || $value === "auto")){
			return;
		}
		if(is_array($expected)){
			if(!is_array($value)){
				throw new \InvalidArgumentException("Invalid $path: expected array");
			}
			if(array_is_list($expected) && !array_is_list($value)){
				throw new \InvalidArgumentException("Invalid $path: expected list");
			}
			foreach(Utils::promoteKeys($expected) as $key => $child){
				if(array_key_exists($key, $value)){
					self::validateShape($value[$key], $child, "$path.$key");
				}
			}
		}elseif(is_int($expected)){
			if(!is_int($value) || $value < 0 && $path !== "network.batch-threshold"){
				throw new \InvalidArgumentException("Invalid $path: expected nonnegative integer");
			}
		}elseif(get_debug_type($value) !== get_debug_type($expected)){
			throw new \InvalidArgumentException("Invalid $path: expected " . get_debug_type($expected));
		}
	}

	/** @param array<string, mixed> $data */
	public static function validatePlugins(array $data) : void{
		if(isset($data["plugins"]) && (!is_array($data["plugins"]) || !array_is_list($data["plugins"]))){
			throw new \InvalidArgumentException("Invalid plugins: expected list in config/plugins.toml");
		}
		PluginGraylist::fromArray($data);
	}
}
