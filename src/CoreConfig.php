<?php

declare(strict_types=1);

namespace quark;

use quark\entity\object\ExperienceOrb;
use quark\entity\object\ItemEntity;
use quark\network\mcpe\bedwire\BedwireTransport;
use quark\player\GameMode;
use quark\plugin\PluginGraylist;
use quark\utils\Utils;
use quark\world\World;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_push;
use function array_replace_recursive;
use function array_slice;
use function count;
use function explode;
use function filter_var;
use function get_debug_type;
use function implode;
use function in_array;
use function inet_pton;
use function is_array;
use function is_bool;
use function is_float;
use function is_infinite;
use function is_int;
use function is_nan;
use function is_string;
use function json_decode;
use function json_encode;
use function mb_check_encoding;
use function ord;
use function preg_match;
use function preg_replace_callback;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_repeat;
use function str_replace;
use function strlen;
use function strpos;
use function strrev;
use function strspn;
use function strtolower;
use function substr;
use function substr_count;
use function substr_replace;
use function toml_encode;
use function toml_parse;
use function toml_parse_file;
use function trim;
use function usort;
use function var_export;
use const FILTER_VALIDATE_IP;
use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;

final class CoreConfig{
	public const SERVER_VERSION = 1;

	/** @var array<string, array<string, mixed>> */
	private static array $loaded = [];
	/** @var array<string, mixed>|null */
	private static ?array $defaults = null;

	/** @return array<string, mixed> */
	public static function defaults() : array{
		return self::$defaults ??= require __DIR__ . "/../generated/CoreConfigSchema.php";
	}

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
		try{
			return toml_encode($data);
		}catch(\TomlException $e){
			throw new \InvalidArgumentException($e->getMessage(), 0, $e);
		}
	}

	/**
	 * Writes $data over an existing document by changing only the entries that differ, so its comments and layout
	 * survive. The result is checked against $data, and anything that can't be patched is written by encode().
	 *
	 * @param array<array-key, mixed> $data
	 */
	public static function update(string $original, array $data) : string{
		try{
			$patched = self::patch($original, $data);
			if($patched !== null && self::equivalent(self::parse($patched), $data)){
				return $patched;
			}
		}catch(\Throwable){
			//patching only keeps comments, so any failure falls back to the plain rewrite, which errors on bad data itself
		}
		return self::encode($data);
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @return string|null null when the change needs the whole document rewritten
	 */
	private static function patch(string $original, array $data) : ?string{
		if(array_key_exists("__quark_nulls", $data)){
			return null;
		}
		$nulls = [];
		self::extractNulls($data, [], $nulls);
		if($nulls !== []){
			$data["__quark_nulls"] = $nulls;
		}
		$old = toml_parse($original);
		if(self::equivalent($old, $data)){
			return $original;
		}
		[$entries, $headers, $tableArrays] = self::scan($original);
		foreach($tableArrays as $path){
			//entries under [[headers]] belong to whichever element came last, so those are left to encode()
			if(!self::equivalent(self::lookup($old, $path), self::lookup($data, $path))){
				return null;
			}
		}

		$edits = [];
		foreach($headers as $header){
			if($header["array"] || self::startsWithAny($header["path"], $tableArrays)){
				continue;
			}
			//a header outliving its table would read back as an empty table, or clash with whatever replaced it
			$value = self::lookup($data, $header["path"]);
			if(!self::exists($data, $header["path"]) || !is_array($value) || ($value !== [] && array_is_list($value)) || self::crossesValue($data, $header["path"])){
				$edits[] = [$header["start"], $header["end"] - $header["start"], ""];
			}
		}
		$covered = [];
		foreach($entries as $entry){
			if($entry["opaque"]){
				continue;
			}
			//an entry inside what is no longer a table goes, and the value replacing that table is written whole instead
			if(self::crossesValue($data, $entry["path"])){
				$edits[] = [$entry["start"], $entry["end"] - $entry["start"], ""];
				continue;
			}
			$covered[json_encode($entry["path"], JSON_THROW_ON_ERROR)] = true;
			if(!self::exists($data, $entry["path"])){
				$edits[] = [$entry["start"], $entry["end"] - $entry["start"], ""];
			}elseif(!self::equivalent($value = self::lookup($data, $entry["path"]), self::lookup($old, $entry["path"]))){
				$edits[] = [$entry["valueStart"], $entry["valueEnd"] - $entry["valueStart"], self::encodeValue($value)];
			}
		}

		$inserts = [];
		$appended = [];
		self::placeAdditions($data, [], $entries, $headers, $tableArrays, $covered, $original, $inserts, $appended);
		$eol = str_contains($original, "\r\n") ? "\r\n" : "\n";
		foreach($inserts as $offset => $text){
			if($offset === strlen($original) && $original !== "" && !str_ends_with($original, "\n")){
				$text = "\n" . $text;
			}
			$edits[] = [$offset, 0, str_replace("\n", $eol, $text)];
		}
		if($appended !== []){
			$text = str_ends_with($original, "\n") || $original === "" ? "" : "\n";
			foreach($appended as [$path, $lines]){
				$text .= "\n[" . implode(".", array_map(self::encodeKey(...), $path)) . "]\n" . implode("", $lines);
			}
			$edits[] = [strlen($original), 0, str_replace("\n", $eol, $text)];
		}
		//later offsets first, and at one offset a removal before an insertion so it can't swallow the new text
		usort($edits, fn(array $a, array $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
		foreach($edits as [$offset, $length, $text]){
			$original = substr_replace($original, $text, $offset, $length);
		}
		return $original;
	}

	/**
	 * Finds where each key of $table missing from the document goes: beside the last entry of its table, after its
	 * header, or in a new table at the end.
	 *
	 * @param array<array-key, mixed>                                                                                                   $table
	 * @param list<string>                                                                                                              $path
	 * @param list<array{path: list<string>, header: list<string>, start: int, valueStart: int, valueEnd: int, end: int, opaque: bool}> $entries
	 * @param list<array{path: list<string>, array: bool, start: int, end: int}>                                                        $headers
	 * @param list<list<string>>                                                                                                        $tableArrays unchanged, as patch() checked
	 * @param array<string, true>                                                                                                       $covered
	 * @param array<int, string>                                                                                                        $inserts
	 * @param array<string, array{list<string>, list<string>}>                                                                          $appended
	 */
	private static function placeAdditions(array $table, array $path, array $entries, array $headers, array $tableArrays, array $covered, string $original, array &$inserts, array &$appended) : void{
		foreach(Utils::promoteKeys($table) as $key => $value){
			$keyPath = [...$path, (string) $key];
			if(isset($covered[json_encode($keyPath, JSON_THROW_ON_ERROR)])){
				continue;
			}
			if(self::startsWithAny($keyPath, $tableArrays)){
				continue;
			}
			if(is_array($value) && $value !== [] && !array_is_list($value) && self::definesTable($keyPath, $entries, $headers)){
				self::placeAdditions($value, $keyPath, $entries, $headers, $tableArrays, $covered, $original, $inserts, $appended);
				continue;
			}
			if($value === []){
				foreach($headers as $header){
					if(!$header["array"] && $header["path"] === $keyPath){
						//its header is kept, and reads back as the empty table
						continue 2;
					}
				}
			}
			$anchor = null;
			foreach($entries as $entry){
				if(!$entry["opaque"] && self::startsWith($path, $entry["header"]) && self::startsWith($entry["path"], $path) && ($anchor === null || $entry["end"] > $anchor["end"])){
					$anchor = $entry;
				}
			}
			if($anchor !== null){
				$offset = $anchor["end"];
				$relative = array_slice($path, count($anchor["header"]));
			}else{
				$offset = null;
				foreach($headers as $header){
					if(!$header["array"] && $header["path"] === $path){
						$offset = $header["end"];
					}
				}
				if($offset === null && $path === []){
					$offset = $headers[0]["start"] ?? strlen($original);
				}
				$relative = [];
			}
			$lines = self::dottedLines($value, [...$relative, (string) $key]);
			if($offset !== null){
				$inserts[$offset] = ($inserts[$offset] ?? "") . implode("", $lines);
			}else{
				$id = json_encode($path, JSON_THROW_ON_ERROR);
				$appended[$id] ??= [$path, []];
				array_push($appended[$id][1], ...$lines);
			}
		}
	}

	/**
	 * @param list<string>                                  $path
	 * @param list<array{path: list<string>, opaque: bool}> $entries
	 * @param list<array{path: list<string>, array: bool}>  $headers
	 */
	private static function definesTable(array $path, array $entries, array $headers) : bool{
		foreach($headers as $header){
			if(self::startsWith($header["path"], $path)){
				return true;
			}
		}
		foreach($entries as $entry){
			if(count($entry["path"]) > count($path) && self::startsWith($entry["path"], $path)){
				return true;
			}
		}
		return false;
	}

	/**
	 * @param list<string> $keyPath
	 * @return list<string>
	 */
	private static function dottedLines(mixed $value, array $keyPath) : array{
		if(!is_array($value) || $value === [] || array_is_list($value)){
			return [implode(".", array_map(self::encodeKey(...), $keyPath)) . " = " . self::encodeValue($value) . "\n"];
		}
		$lines = [];
		foreach(Utils::promoteKeys($value) as $key => $element){
			array_push($lines, ...self::dottedLines($element, [...$keyPath, (string) $key]));
		}
		return $lines;
	}

	/**
	 * Lists the document's key/value entries and table headers with their byte offsets.
	 *
	 * @return array{
	 *     list<array{path: list<string>, header: list<string>, start: int, valueStart: int, valueEnd: int, end: int, opaque: bool}>,
	 *     list<array{path: list<string>, array: bool, start: int, end: int}>,
	 *     list<list<string>>
	 * }
	 */
	private static function scan(string $document) : array{
		$entries = [];
		$headers = [];
		$tableArrays = [];
		$header = [];
		$opaque = false;
		$length = strlen($document);
		$i = 0;
		while($i < $length){
			$start = $i;
			$i += strspn($document, " \t", $i);
			$char = $document[$i] ?? "\n";
			if($char === "\n" || $char === "\r" || $char === "#"){
				$i = self::lineEnd($document, $i);
				continue;
			}
			if($char === "["){
				$array = ($document[$i + 1] ?? "") === "[";
				[$header, $i] = self::scanKey($document, $i + ($array ? 2 : 1));
				if($array){
					$tableArrays[] = $header;
				}
				$opaque = self::startsWithAny($header, $tableArrays);
				$i = self::lineEnd($document, $i);
				$headers[] = ["path" => $header, "array" => $array, "start" => $start, "end" => $i];
				continue;
			}
			[$key, $i] = self::scanKey($document, $i);
			$i += strspn($document, " \t", $i) + 1;
			$i += strspn($document, " \t", $i);
			$valueEnd = self::scanValue($document, $i);
			$end = self::lineEnd($document, $valueEnd);
			$entries[] = ["path" => [...$header, ...$key], "header" => $header, "start" => $start, "valueStart" => $i, "valueEnd" => $valueEnd, "end" => $end, "opaque" => $opaque];
			$i = $end;
		}
		return [$entries, $headers, $tableArrays];
	}

	/** @return array{list<string>, int} the key's segments and the offset after it */
	private static function scanKey(string $document, int $i) : array{
		$segments = [];
		while(true){
			$i += strspn($document, " \t", $i);
			$char = $document[$i] ?? "";
			if($char === '"' || $char === "'"){
				$end = self::stringEnd($document, $i);
				$segment = toml_parse("k = " . substr($document, $i, $end - $i))["k"];
				$segments[] = is_string($segment) ? $segment : throw new \InvalidArgumentException("Invalid quoted key");
				$i = $end;
			}else{
				$bare = strspn($document, "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_-", $i);
				$segments[] = substr($document, $i, $bare);
				$i += $bare;
			}
			$next = $i + strspn($document, " \t", $i);
			if(($document[$next] ?? "") !== "."){
				return [$segments, $i];
			}
			$i = $next + 1;
		}
	}

	/**
	 * Returns the offset just past a value, before any trailing comment, following arrays and strings across lines.
	 */
	private static function scanValue(string $document, int $i) : int{
		$length = strlen($document);
		$depth = 0;
		$end = $i;
		while($i < $length){
			$char = $document[$i];
			if($char === '"' || $char === "'"){
				$triple = str_repeat($char, 3);
				if(substr($document, $i, 3) === $triple){
					$close = $i + 3;
					while(($close = strpos($document, $triple, $close)) !== false && $char === '"' && strspn(strrev(substr($document, 0, $close)), "\\") % 2 === 1){
						++$close;
					}
					$i = $close === false ? $length : $close + 3;
				}else{
					$i = self::stringEnd($document, $i);
				}
				$end = $i;
				continue;
			}
			if($char === "#" || $char === "\n" || $char === "\r"){
				if($depth === 0){
					break;
				}
				$i = $char === "#" ? self::lineEnd($document, $i) : $i + 1;
				continue;
			}
			if($char === "[" || $char === "{"){
				++$depth;
			}elseif($char === "]" || $char === "}"){
				--$depth;
			}
			if($char !== " " && $char !== "\t"){
				$end = $i + 1;
			}
			++$i;
		}
		return $end;
	}

	/** Returns the offset just past a single-line string starting at $i. */
	private static function stringEnd(string $document, int $i) : int{
		$quote = $document[$i];
		$length = strlen($document);
		for($j = $i + 1; $j < $length; ++$j){
			if($quote === '"' && $document[$j] === "\\"){
				++$j;
			}elseif($document[$j] === $quote){
				return $j + 1;
			}
		}
		return $length;
	}

	private static function lineEnd(string $document, int $i) : int{
		$newline = strpos($document, "\n", $i);
		return $newline === false ? strlen($document) : $newline + 1;
	}

	/**
	 * Returns whether something above $path in $data is no longer a table, such as a table turned into a list.
	 *
	 * @param array<array-key, mixed> $data
	 * @param list<string>            $path
	 */
	private static function crossesValue(array $data, array $path) : bool{
		for($length = 1, $count = count($path); $length < $count; ++$length){
			$value = self::lookup($data, array_slice($path, 0, $length));
			if(!is_array($value) || ($value !== [] && array_is_list($value))){
				return true;
			}
		}
		return false;
	}

	/**
	 * @param list<string>       $path
	 * @param list<list<string>> $prefixes
	 */
	private static function startsWithAny(array $path, array $prefixes) : bool{
		foreach($prefixes as $prefix){
			if(self::startsWith($path, $prefix)){
				return true;
			}
		}
		return false;
	}

	/**
	 * @param list<string> $path
	 * @param list<string> $prefix
	 */
	private static function startsWith(array $path, array $prefix) : bool{
		return array_slice($path, 0, count($prefix)) === $prefix;
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @param list<string>            $path
	 */
	private static function exists(array $data, array $path) : bool{
		foreach($path as $key){
			if(!is_array($data) || !array_key_exists($key, $data)){
				return false;
			}
			$data = $data[$key];
		}
		return true;
	}

	/**
	 * @param array<array-key, mixed> $data
	 * @param list<string>            $path
	 */
	private static function lookup(array $data, array $path) : mixed{
		foreach($path as $key){
			if(!is_array($data) || !array_key_exists($key, $data)){
				return null;
			}
			$data = $data[$key];
		}
		return $data;
	}

	public static function equivalent(mixed $left, mixed $right) : bool{
		if(!is_array($left) || !is_array($right)){
			return $left === $right || (is_float($left) && is_float($right) && is_nan($left) && is_nan($right));
		}
		if(count($left) !== count($right)){
			return false;
		}
		foreach(Utils::promoteKeys($left) as $key => $value){
			if(!array_key_exists($key, $right) || !self::equivalent($value, $right[$key])){
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

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $overrides
	 */
	public static function validateServer(array $data, array $overrides = []) : void{
		self::validateServerValues($data);
		if($overrides !== []){
			self::validateServerValues($overrides);
			$data = array_replace_recursive($data, $overrides);
		}
		self::validateServerCombinations($data);
	}

	/** @param array<string, mixed> $data */
	private static function validateServerValues(array $data) : void{
		if(array_key_exists("config-version", $data)){
			$version = $data["config-version"];
			if(!is_int($version) || $version < 0 || $version > self::SERVER_VERSION){
				throw new \InvalidArgumentException("Unsupported config-version in config/server.toml: expected an integer from 0 to " . self::SERVER_VERSION);
			}
		}
		$defaults = self::defaults();
		foreach(Utils::stringifyKeys($data) as $key => $value){
			if($key !== "config-version" && array_key_exists($key, $defaults)){
				self::validateShape($value, $defaults[$key], $key);
			}
		}
		$portRange = ["transport", "nethernet", "port-range"];
		if(self::exists($data, $portRange)){
			self::validateNetherNetPortRange(self::lookup($data, $portRange));
		}
		$advertised = ["transport", "nethernet", "advertise-addresses"];
		if(self::exists($data, $advertised)){
			self::validateNetherNetAdvertiseAddresses(self::lookup($data, $advertised));
		}

		$logging = $data["logging"] ?? [];
		if(is_array($logging) && $logging !== []){
			new log\LoggingConfig($logging);
		}

		foreach(["aliases", "worlds"] as $section){
			if(!array_key_exists($section, $data)){
				continue;
			}
			$entries = $data[$section];
			if(!is_array($entries) || $entries !== [] && array_is_list($entries)){
				throw new \InvalidArgumentException("Invalid $section: expected table");
			}
			foreach(Utils::promoteKeys($entries) as $key => $value){
				if($section === "aliases"){
					if(!is_string($value)){
						self::validateStringList($value, "$section.$key");
					}
					continue;
				}
				$path = "worlds.$key";
				if(!is_array($value) || $value !== [] && array_is_list($value)){
					throw new \InvalidArgumentException("Invalid $path: expected table");
				}
				foreach(["generator", "preset", "difficulty"] as $field){
					if(array_key_exists($field, $value) && !is_string($value[$field])){
						throw new \InvalidArgumentException("Invalid $path.$field: expected string");
					}
				}
				if(array_key_exists("seed", $value) && !is_int($value["seed"]) && !is_string($value["seed"])){
					throw new \InvalidArgumentException("Invalid $path.seed: expected string or integer");
				}
				if(isset($value["difficulty"]) && is_string($value["difficulty"]) && World::getDifficultyFromString($value["difficulty"]) < 0){
					throw new \InvalidArgumentException("Invalid $path.difficulty: expected peaceful, easy, normal, or hard");
				}
			}
		}
	}

	/** @param array<string, mixed> $data */
	private static function validateServerCombinations(array $data) : void{
		$port = self::lookup($data, ["server", "server-port"]) ?? Server::DEFAULT_PORT_IPV4;
		if(
			self::lookup($data, ["transport", "nethernet", "port-range"]) === [$port, $port] &&
			(self::lookup($data, ["server", "enable-query"]) ?? true) === true &&
			self::lookup($data, ["transport", "nethernet", "ice-udp-mux"]) === true
		){
			$transports = self::lookup($data, ["transport", "name"]);
			if(!is_string($transports) || in_array(BedwireTransport::NAME, array_map(trim(...), explode(",", strtolower($transports), Server::MAX_ACTIVE_TRANSPORTS + 1)), true)){
				throw new \InvalidArgumentException("Invalid transport.nethernet.port-range: the server port is already used by Query");
			}
		}

		$hardLimitPath = ["memory", "main-hard-limit"];
		$hardLimit = self::lookup($data, $hardLimitPath) ?? self::lookup(self::defaults(), $hardLimitPath);
		$softLimit = self::lookup($data, ["memory", "main-limit"]);
		if(is_int($hardLimit) && is_int($softLimit) && $hardLimit > 0 && $softLimit > $hardLimit){
			throw new \InvalidArgumentException("Invalid memory.main-limit: exceeds memory.main-hard-limit");
		}
	}

	/** @return array{int, int} */
	public static function validateNetherNetPortRange(mixed $range) : array{
		if(!is_array($range) || !array_is_list($range) || count($range) !== 2 || !is_int($range[0]) || !is_int($range[1]) || $range[0] < 1 || $range[0] > $range[1] || $range[1] > 65535){
			throw new \InvalidArgumentException("Invalid transport.nethernet.port-range: expected [min, max] with 1 <= min <= max <= 65535");
		}
		return [$range[0], $range[1]];
	}

	/**
	 * These are addresses the server tells clients to reach it on, not an allowlist of clients.
	 *
	 * @return list<string>
	 */
	public static function validateNetherNetAdvertiseAddresses(mixed $addresses) : array{
		$error = "Invalid transport.nethernet.advertise-addresses: expected up to 8 distinct IP addresses without ports or brackets";
		if(!is_array($addresses) || !array_is_list($addresses) || count($addresses) > 8){
			throw new \InvalidArgumentException($error);
		}
		$seen = [];
		foreach($addresses as $address){
			$packed = is_string($address) && filter_var($address, FILTER_VALIDATE_IP) !== false ? inet_pton($address) : false;
			//the unspecified, multicast, link-local and IPv4-mapped ranges can't name a server
			if($packed === false || isset($seen[$packed]) || preg_match('/^(0\.|22[4-9]\.|2[3-5]\d\.|169\.254\.|::$|ff|fe[89ab]|::ffff:)/i', $address) === 1){
				throw new \InvalidArgumentException($error . ", got " . (is_string($address) ? "'$address'" : get_debug_type($address)));
			}
			$seen[$packed] = true;
		}
		return $addresses;
	}

	private static function validateShape(mixed $value, mixed $expected, string $path) : void{
		if($path === "settings.async-workers"){
			if(!(is_int($value) && $value > 0 || $value === "auto")){
				throw new \InvalidArgumentException("Invalid $path: expected a positive integer or 'auto'");
			}
		}elseif(is_array($expected)){
			if($expected === []){
				self::validateStringList($value, $path, $path === "chunk-ticking.disable-block-ticking");
				return;
			}
			if(!is_array($value) || $value !== [] && array_is_list($value)){
				throw new \InvalidArgumentException("Invalid $path: expected table");
			}
			foreach(Utils::promoteKeys($expected) as $key => $child){
				if(array_key_exists($key, $value)){
					self::validateShape($value[$key], $child, "$path.$key");
				}
			}
		}elseif(is_float($expected)){
			$max = $path === "entities.item-merge-radius" ? ItemEntity::MAX_MERGE_RADIUS : ExperienceOrb::MAX_MERGE_RADIUS;
			if((!is_float($value) && !is_int($value)) || is_nan((float) $value) || $value < 0 || $value > $max){
				throw new \InvalidArgumentException("Invalid $path: expected a number from 0 to $max");
			}
		}elseif(is_int($expected)){
			if(!is_int($value)){
				throw new \InvalidArgumentException("Invalid $path: expected integer");
			}
			[$min, $max] = match($path){
				"server.server-port", "server.server-portv6" => [1, 65535],
				"server.max-players" => [1, 0xffffffff],
				"server.difficulty" => [0, 3],
				"network.batch-threshold" => [-1, PHP_INT_MAX],
				"network.compression-level" => [1, 9],
				"network.max-mtu-size" => [\raklib\generic\Session::MIN_MTU_SIZE, 65535],
				"diagnostics.max-crash-reports", "diagnostics.max-profiles" => [0, 100000],
				"entities.activation-range" => [0, World::MAX_ACTIVATION_RANGE],
				"chunk-sending.spawn-radius" => [0, Server::MAX_VIEW_DISTANCE],
				"memory.global-limit", "memory.main-limit", "memory.main-hard-limit", "memory.async-worker-hard-limit" => [0, PHP_INT_MAX >> 20],
				"server.view-distance", "ticks-per.autosave",
				"memory.check-rate", "memory.continuous-trigger-rate", "entities.inactive-tick-interval",
				"chunk-sending.per-tick", "chunk-generation.population-queue-size",
				"redstone.max-wire-network-size", "redstone.max-scheduled-updates", "redstone.max-updates-per-tick",
				"redstone.max-piston-actions-per-tick", "redstone.max-dispenser-actions-per-tick" => [1, PHP_INT_MAX],
				default => [0, PHP_INT_MAX]
			};
			if($value < $min || $value > $max){
				throw new \InvalidArgumentException("Invalid $path: expected integer from $min to $max");
			}
		}elseif(is_string($expected)){
			if(!is_string($value)){
				throw new \InvalidArgumentException("Invalid $path: expected string");
			}
			$choices = match($path){
				"network.compression-algorithm" => ["zlib", "snappy"],
				"redstone.world-policy" => ["all", "allowlist", "blocklist"],
				"level-settings.format-migration" => ["off", "manual", "automatic"],
				default => []
			};
			$normalized = match($path){
				"level-settings.format-migration" => $value,
				"redstone.world-policy" => strtolower(trim($value)),
				default => strtolower($value)
			};
			if($choices !== [] && !in_array($normalized, $choices, true)){
				throw new \InvalidArgumentException("Invalid $path: expected " . implode(", ", $choices));
			}
			if($path === "server.gamemode" && GameMode::fromString($value) === null){
				throw new \InvalidArgumentException("Invalid $path: expected survival, creative, adventure, or spectator");
			}
			if($path === "transport.name" && (trim($value) === "" || substr_count($value, ",") >= Server::MAX_ACTIVE_TRANSPORTS)){
				throw new \InvalidArgumentException("Invalid $path: expected 1 to " . Server::MAX_ACTIVE_TRANSPORTS . " comma-separated transports");
			}
		}elseif(get_debug_type($value) !== get_debug_type($expected)){
			throw new \InvalidArgumentException("Invalid $path: expected " . get_debug_type($expected));
		}
	}

	private static function validateStringList(mixed $value, string $path, bool $allowLegacyIds = false) : void{
		if(!is_array($value) || !array_is_list($value)){
			throw new \InvalidArgumentException("Invalid $path: expected list");
		}
		foreach($value as $index => $entry){
			if((!is_string($entry) || trim($entry) === "") && !($allowLegacyIds && is_int($entry))){
				throw new \InvalidArgumentException("Invalid $path entry $index: expected a nonempty string");
			}
		}
	}

	/** @param array<string, mixed> $data */
	public static function validatePlugins(array $data) : void{
		PluginGraylist::fromArray($data);
	}

	/**
	 * @param array<string, mixed> $data
	 * @phpstan-assert array{force_resources?: bool, resource_stack?: list<string>} $data
	 */
	public static function validateResourcePacks(array $data) : void{
		if(array_key_exists("force_resources", $data) && !is_bool($data["force_resources"])){
			throw new \InvalidArgumentException("Invalid force_resources in resource_packs.toml: expected boolean");
		}
		if(array_key_exists("resource_stack", $data)){
			self::validateStringList($data["resource_stack"], "resource_stack");
		}
	}
}
