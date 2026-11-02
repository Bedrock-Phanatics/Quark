<?php

declare(strict_types=1);

namespace quark;

use quark\plugin\PluginGraylist;
use quark\utils\Utils;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_push;
use function array_slice;
use function count;
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
use function ksort;
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
use function substr;
use function substr_replace;
use function toml_encode;
use function toml_parse;
use function toml_parse_file;
use function usort;
use function var_export;
use const JSON_THROW_ON_ERROR;
use const SORT_STRING;

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
			if($patched !== null && self::canonical(self::parse($patched)) === self::canonical($data)){
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
		if(self::canonical($old) === self::canonical($data)){
			return $original;
		}
		[$entries, $headers, $tableArrays] = self::scan($original);
		foreach($tableArrays as $path){
			//entries under [[headers]] belong to whichever element came last, so those are left to encode()
			if(self::canonical(self::lookup($old, $path)) !== self::canonical(self::lookup($data, $path))){
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
			}elseif(self::canonical($value = self::lookup($data, $entry["path"])) !== self::canonical(self::lookup($old, $entry["path"]))){
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

	private static function canonical(mixed $value) : mixed{
		if(!is_array($value)){
			return $value;
		}
		$value = array_map(self::canonical(...), $value);
		if(!array_is_list($value)){
			ksort($value, SORT_STRING);
		}
		return $value;
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
