<?php

declare(strict_types=1);

namespace quark;

use Internal\Toml\Toml;
use quark\plugin\PluginGraylist;
use quark\utils\Utils;

final class CoreConfig{
	/** @var array<string, array<string, mixed>> */
	private static array $loaded = [];
	/** @var array<string, mixed>|null */
	private static ?array $defaults = null;

	/** @return array<string, mixed> */
	public static function load(string $path) : array{
		if(!isset(self::$loaded[$path])){
			$content = @file_get_contents($path);
			if($content === false){
				throw new \RuntimeException("Cannot read configuration: $path");
			}
			try{
				self::$loaded[$path] = self::parse($content);
			}catch(\Throwable $e){
				throw new \RuntimeException("Invalid TOML in $path: " . $e->getMessage(), 0, $e);
			}
		}
		return self::$loaded[$path];
	}

	public static function forget(string $path) : void{
		unset(self::$loaded[$path]);
	}

	/** @return array<string, mixed> */
	public static function parse(string $content) : array{
		$data = Toml::parseToArray($content);
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

	/** @param array<string, mixed> $data */
	public static function encode(array $data) : string{
		if(array_key_exists("__quark_nulls", $data)){
			throw new \InvalidArgumentException("Reserved configuration key: __quark_nulls");
		}
		$nulls = [];
		self::extractNulls($data, [], $nulls);
		if($nulls !== []){
			$data["__quark_nulls"] = $nulls;
		}
		return (string) Toml::encode($data);
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
