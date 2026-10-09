<?php

/*
 *
 *   ___  _   _   _    ____  _  __
 *  / _ \| | | | / \  |  _ \| |/ /
 * | | | | | | |/ _ \ | |_) | ' /
 * | |_| | |_| / ___ \|  _ <| . \
 *  \__\_|\___/_/   \_\_| \_\_|\_\
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Quark Team
 * @link https://github.com/Bedrock-Phanatics/Quark
 *
 *
 */

declare(strict_types=1);

namespace quark\pulse;

use pocketmine\errorhandler\ErrorToExceptionHandler;
use quark\pulse\internal\PulseContext;
use quark\utils\Filesystem;
use quark\utils\Utils;
use quark\VersionInfo;
use Symfony\Component\Filesystem\Path;
use function array_is_list;
use function bin2hex;
use function count;
use function date;
use function get_object_vars;
use function gzencode;
use function inflate_add;
use function inflate_get_read_len;
use function inflate_get_status;
use function inflate_init;
use function intdiv;
use function is_array;
use function is_bool;
use function is_dir;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function microtime;
use function min;
use function mkdir;
use function php_uname;
use function preg_match;
use function random_bytes;
use function str_starts_with;
use function strlen;
use function substr;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_INT_MAX;
use const PHP_VERSION;
use const ZLIB_ENCODING_GZIP;
use const ZLIB_STREAM_END;

/**
 * @phpstan-import-type Capture from PulseContext
 * @phpstan-type Metadata array{quark_version: string, php_version: string, platform: string, cpu_threads: int, created_unix_ms: int, plugins: list<array{name: string, version: string}>, worlds: list<string>}
 * @phpstan-type ReportData array{format: string, version: int, time_unit: string, metadata: Metadata, threads: list<Capture>}
 */
final class PulseReport{
	// v1 is UTF-8 JSON, gzip-wrapped on disk; times are nanoseconds.
	// Node: id, zone, parent, calls, total, self, max. active_ticks follows node order.
	// Tick: id, session offset, duration. Spike node: id, calls, total, self.
	public const FORMAT_VERSION = 1;
	public const MAX_BYTES = 8388608;
	private const MAX_ROWS = 131072;

	/** @param ReportData $data */
	private function __construct(private readonly array $data){}

	/**
	 * @param list<Capture>        $threads
	 * @param array<string, mixed> $metadata
	 */
	public static function create(array $threads, array $metadata = []) : self{
		$data = [
			"format" => "quark.pulse",
			"version" => self::FORMAT_VERSION,
			"time_unit" => "ns",
			"metadata" => $metadata + [
				"quark_version" => VersionInfo::BASE_VERSION,
				"php_version" => PHP_VERSION,
				"platform" => php_uname(),
				"cpu_threads" => Utils::getCoreCount(),
				"created_unix_ms" => (int) (microtime(true) * 1000),
				"plugins" => [],
				"worlds" => []
			],
			"threads" => $threads
		];
		self::validate($data);
		return new self($data);
	}

	/** @return ReportData */
	public function getData() : array{ return $this->data; }

	public function encode(bool $compress = false) : string{
		$json = json_encode($this->data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		if(strlen($json) > self::MAX_BYTES){
			throw new \LengthException("Pulse report exceeds the size limit");
		}
		if(!$compress){ return $json; }
		$gzip = gzencode($json, 1);
		if($gzip === false){ throw new \RuntimeException("Failed to compress Pulse report"); }
		if(strlen($gzip) > self::MAX_BYTES){ throw new \LengthException("Pulse report exceeds the size limit"); }
		return $gzip;
	}

	public static function decode(string $json) : self{
		if(strlen($json) > self::MAX_BYTES){
			throw new \LengthException("Pulse report exceeds the size limit");
		}
		if(str_starts_with($json, "\x1f\x8b")){
			$json = self::decompress($json);
		}
		self::checkJsonBudget($json);
		try{
			$data = self::normalizeJson(json_decode($json, false, 16, JSON_THROW_ON_ERROR));
		}catch(\JsonException $e){
			throw new \InvalidArgumentException("Invalid Pulse report JSON", 0, $e);
		}
		self::validate($data);
		return new self($data);
	}

	private static function decompress(string $gzip) : string{
		try{
			return ErrorToExceptionHandler::trap(static function() use ($gzip) : string{
				$context = inflate_init(ZLIB_ENCODING_GZIP);
				if($context === false){ throw new \InvalidArgumentException("Failed to initialize Pulse gzip decoder"); }
				$json = "";
				$length = strlen($gzip);
				// Small input chunks bound temporary output on highly compressed input.
				for($offset = 0; $offset < $length; $offset += 1024){
					$part = inflate_add($context, substr($gzip, $offset, 1024));
					if($part === false){ throw new \InvalidArgumentException("Invalid Pulse gzip data"); }
					if(strlen($part) > self::MAX_BYTES - strlen($json)){ throw new \LengthException("Decompressed Pulse report exceeds the size limit"); }
					$json .= $part;
					if(inflate_get_status($context) === ZLIB_STREAM_END){
						if(inflate_get_read_len($context) !== $length){ throw new \InvalidArgumentException("Unexpected data after Pulse gzip stream"); }
						return $json;
					}
				}
				throw new \InvalidArgumentException("Truncated Pulse gzip stream");
			});
		}catch(\ErrorException $e){
			throw new \InvalidArgumentException("Invalid Pulse gzip data", 0, $e);
		}
	}

	private static function normalizeJson(mixed $value) : mixed{
		if($value instanceof \stdClass){
			$value = get_object_vars($value);
			// Objects must not masquerade as lists after conversion.
			if(array_is_list($value)){
				throw new \InvalidArgumentException("Invalid Pulse object");
			}
		}
		if(is_array($value)){
			foreach(Utils::promoteKeys($value) as $key => $item){
				$value[$key] = self::normalizeJson($item);
			}
		}
		return $value;
	}

	public function write(string $directory) : string{
		$json = $this->encode(true);
		if(!@mkdir($directory, 0777, true) && !is_dir($directory)){
			throw new \RuntimeException("Failed to create Pulse report directory");
		}
		$file = Path::join($directory, "pulse_" . date("Y-m-d_H.i.s") . "_" . bin2hex(random_bytes(8)) . ".qpulse");
		Filesystem::safeFilePutContents($file, $json);
		return $file;
	}

	private static function checkJsonBudget(string $json) : void{
		// Bound decoder allocations before building PHP arrays.
		$quoted = $escaped = false;
		$containers = $items = 0;
		$length = strlen($json);
		for($i = 0; $i < $length; ++$i){
			$char = $json[$i];
			if($quoted){
				if($escaped){
					$escaped = false;
				}elseif($char === "\\"){
					$escaped = true;
				}elseif($char === '"'){
					$quoted = false;
				}
			}elseif($char === '"'){
				$quoted = true;
			}elseif($char === "[" || $char === "{"){
				if(++$containers > 300000){
					throw new \LengthException("Pulse report has too many containers");
				}
			}elseif($char === "," && ++$items > 1500000){
				throw new \LengthException("Pulse report has too many values");
			}
		}
	}

	/** @phpstan-assert ReportData $data */
	private static function validate(mixed $data) : void{
		if(!is_array($data) || count($data) !== 5 || ($data["format"] ?? null) !== "quark.pulse" || ($data["version"] ?? null) !== self::FORMAT_VERSION || ($data["time_unit"] ?? null) !== "ns"){
			throw new \InvalidArgumentException("Unsupported Pulse report format or version");
		}
		$bytes = intdiv(self::MAX_BYTES, 2);
		$metadata = $data["metadata"] ?? null;
		if(!is_array($metadata) || count($metadata) !== 7){
			throw new \InvalidArgumentException("Invalid Pulse metadata");
		}
		foreach(["quark_version", "php_version", "platform"] as $key){
			self::text($metadata[$key] ?? null, 4096, $bytes);
		}
		self::number($metadata["cpu_threads"] ?? null, 0, 65536);
		self::number($metadata["created_unix_ms"] ?? null);
		foreach(self::list($metadata["plugins"] ?? null, 1024) as $plugin){
			if(!is_array($plugin) || count($plugin) !== 2){
				throw new \InvalidArgumentException("Invalid Pulse plugin metadata");
			}
			self::text($plugin["name"] ?? null, 256, $bytes);
			self::text($plugin["version"] ?? null, 256, $bytes);
		}
		foreach(self::list($metadata["worlds"] ?? null, 4096) as $world){
			self::text($world, 256, $bytes);
		}
		$rows = self::MAX_ROWS;
		$names = [];
		foreach(self::list($data["threads"] ?? null, 128) as $thread){
			self::validateThread($thread, $rows, $bytes);
			if(isset($names[$thread["thread"]])){
				throw new \InvalidArgumentException("Duplicate Pulse thread name");
			}
			$names[$thread["thread"]] = true;
		}
	}

	/** @phpstan-assert Capture $thread */
	private static function validateThread(mixed $thread, int &$budget, int &$bytes) : void{
		if(!is_array($thread) || count($thread) !== 20){
			throw new \InvalidArgumentException("Invalid Pulse thread data");
		}
		self::text($thread["thread"] ?? null, 256, $bytes);
		self::number($thread["started_ns"] ?? null);
		self::number($thread["ended_ns"] ?? null, $thread["started_ns"]);
		if(!is_bool($thread["recording"] ?? null)){
			throw new \InvalidArgumentException("Invalid Pulse recording state");
		}
		foreach(["unbalanced_ticks", "unbalanced_scopes", "dropped_scopes", "spikes_dropped"] as $key){
			self::number($thread[$key] ?? null);
		}
		$tickCount = self::number($thread["tick_count"] ?? null);
		$tickTotal = self::number($thread["tick_total_ns"] ?? null);
		$tickMax = self::number($thread["tick_max_ns"] ?? null, 0, $tickTotal);
		self::number($thread["tick_capacity"] ?? null, 1, 4096);
		self::number($thread["max_spikes"] ?? null, 1, 128);
		self::number($thread["spike_threshold_ns"] ?? null, 0, 60000000000);
		self::number($thread["duration_ns"] ?? null, 0, 86400000000000);
		$zones = self::list($thread["zones"] ?? null, 4096);
		$zoneNames = [];
		foreach($zones as $zone){
			self::text($zone, 256, $bytes);
			if(isset($zoneNames[$zone])){
				throw new \InvalidArgumentException("Duplicate Pulse zone name");
			}
			$zoneNames[$zone] = true;
		}
		$nodes = self::list($thread["nodes"] ?? null, 16384);
		$active = self::list($thread["active_ticks"] ?? null, 16384);
		if(count($nodes) !== count($active)){
			throw new \InvalidArgumentException("Invalid Pulse active tick counters");
		}
		self::charge($budget, count($nodes));
		foreach($nodes as $index => $node){
			self::tuple($node, 7);
			if($node[0] !== $index + 1 || $node[1] >= count($zones) || $node[2] >= $node[0] || $node[5] > $node[4] || $node[6] > $node[4] || ($node[3] === 0 && $node[4] !== 0)){
				throw new \InvalidArgumentException("Invalid Pulse hierarchy or measurements");
			}
			$activeLimit = $tickCount;
			if($thread["recording"] && $activeLimit < PHP_INT_MAX){
				++$activeLimit;
			}
			self::number($active[$index], 0, min($activeLimit, $node[3]));
		}
		/** @var list<array{int, int, int, int, int, int, int}> $nodes */
		$ticks = self::list($thread["ticks"] ?? null, $thread["tick_capacity"]);
		if(count($ticks) !== min($tickCount, $thread["tick_capacity"])){
			throw new \InvalidArgumentException("Invalid Pulse tick history length");
		}
		self::charge($budget, count($ticks));
		$length = $thread["ended_ns"] - $thread["started_ns"];
		$previousEnd = $sum = 0;
		foreach($ticks as $index => $tick){
			self::tick($tick, $tickCount, $length, $tickMax);
			if($tick[0] !== $tickCount - count($ticks) + $index + 1 || $tick[1] < $previousEnd || $tick[2] > $tickTotal - $sum){
				throw new \InvalidArgumentException("Invalid Pulse tick ordering or total");
			}
			$previousEnd = $tick[1] + $tick[2];
			$sum += $tick[2];
		}
		$previousId = $spikeRows = 0;
		$spikes = self::list($thread["spikes"] ?? null, $thread["max_spikes"]);
		self::charge($budget, count($spikes));
		foreach($spikes as $spike){
			if(!is_array($spike) || count($spike) !== 2){
				throw new \InvalidArgumentException("Invalid Pulse spike");
			}
			$tick = $spike["tick"] ?? null;
			self::tick($tick, $tickCount, $length, $tickMax);
			if($tick[0] <= $previousId || $thread["spike_threshold_ns"] === 0 || $tick[2] <= $thread["spike_threshold_ns"]){
				throw new \InvalidArgumentException("Invalid Pulse spike threshold or ordering");
			}
			$previousId = $tick[0];
			$historyIndex = $tick[0] - ($tickCount - count($ticks) + 1);
			if($historyIndex >= 0 && ($ticks[$historyIndex] ?? null) !== $tick){
				throw new \InvalidArgumentException("Pulse spike disagrees with tick history");
			}
			$details = self::list($spike["nodes"] ?? null, count($nodes));
			self::charge($budget, count($details));
			$spikeRows += count($details);
			if($spikeRows > PulseContext::MAX_SPIKE_ROWS){
				throw new \LengthException("Pulse spike storage exceeds the row limit");
			}
			$seen = [];
			foreach($details as $detail){
				self::tuple($detail, 4);
				$node = $nodes[$detail[0] - 1] ?? null;
				if($node === null || isset($seen[$detail[0]]) || $detail[1] < 1 || $detail[1] > $node[3] || $detail[2] > $node[4] || $detail[2] > $tick[2] || $detail[3] > $detail[2] || $detail[3] > $node[5]){
					throw new \InvalidArgumentException("Invalid Pulse spike node");
				}
				$seen[$detail[0]] = true;
			}
		}
	}

	/** @phpstan-assert int $value */
	private static function number(mixed $value, int $min = 0, int $max = PHP_INT_MAX) : int{
		if(!is_int($value) || $value < $min || $value > $max){
			throw new \InvalidArgumentException("Invalid Pulse integer");
		}
		return $value;
	}

	/** @phpstan-assert string $value */
	private static function text(mixed $value, int $max, int &$budget) : void{
		if(!is_string($value) || $value === "" || strlen($value) > $max || preg_match('//u', $value) !== 1){
			throw new \InvalidArgumentException("Invalid Pulse text");
		}
		$budget -= strlen($value);
		if($budget < 0){
			throw new \LengthException("Pulse report exceeds the text budget");
		}
	}

	/** @return list<mixed> */
	private static function list(mixed $value, int $max) : array{
		if(!is_array($value) || !array_is_list($value) || count($value) > $max){
			throw new \InvalidArgumentException("Invalid or oversized Pulse list");
		}
		return $value;
	}

	/** @phpstan-assert list<int> $value */
	private static function tuple(mixed $value, int $size) : void{
		$valueList = self::list($value, $size);
		if(count($valueList) !== $size){
			throw new \InvalidArgumentException("Invalid Pulse row length");
		}
		foreach($valueList as $number){
			self::number($number);
		}
	}

	/** @phpstan-assert array{int, int, int} $tick */
	private static function tick(mixed $tick, int $count, int $length, int $max) : void{
		self::tuple($tick, 3);
		if($tick[0] < 1 || $tick[0] > $count || $tick[1] > $length || $tick[2] > $length - $tick[1] || $tick[2] > $max){
			throw new \InvalidArgumentException("Invalid Pulse tick");
		}
	}

	private static function charge(int &$budget, int $count) : void{
		$budget -= $count;
		if($budget < 0){
			throw new \LengthException("Pulse report exceeds the row budget");
		}
	}
}
