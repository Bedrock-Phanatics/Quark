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

use quark\pulse\internal\PulseContext;
use quark\pulse\internal\PulseNetwork;
use quark\utils\Utils;
use function array_slice;
use function arsort;
use function count;
use function hrtime;
use function max;
use function str_ends_with;
use function str_starts_with;
use function strcmp;
use function uasort;
use function usort;
use const SORT_NUMERIC;

/**
 * @phpstan-import-type Capture from PulseContext
 * @phpstan-import-type Spike from PulseContext
 * @phpstan-import-type Stats from PulseContext
 * @phpstan-import-type TransportCapture from PulseNetwork
 * @phpstan-type TopZone array{name: string, calls: int, self_ns: int, max_ns: int}
 * @phpstan-type CategoryCost array{string, int}
 * @phpstan-type SpikeSummary array{tick_id: int, offset_ns: int, duration_ns: int, zone: string|null, self_ns: int, categories: list<CategoryCost>, unattributed_ns: int}
 * @phpstan-type SpikeBreakdown array{spikes: int, duration_ns: int, categories: list<CategoryCost>, unattributed_ns: int}
 * @phpstan-type PluginSummary array{name: string, calls: int, total_ns: int, self_ns: int, max_ns: int, tick_share: float|null}
 * @phpstan-type NetworkSummary array{batches: int, packets: int, received_bytes: int, decompressed_bytes: int, decoded: int, decode_failed: int, plugin_cancelled: int, state_dropped: int, repeated_dropped: int, enforcement_events: int, enforcement_events_dropped: int, windows_dropped: int, transports: list<TransportCapture>}
 */
final class PulseSession{
	public const UNATTRIBUTED = "unattributed";
	/** Zones under world.<name> are classified by suffix so world names never matter. */
	private const WORLD_SUFFIXES = [
		".entities" => "entities",
		".chunk.populate" => "chunk generation", ".chunk.populate_complete" => "chunk generation",
		".chunk.save" => "chunk storage", ".save" => "chunk storage",
		".chunk.load" => "chunk loading", ".chunk.load_data" => "chunk loading", ".chunk.load_entities" => "chunk loading", ".chunk.load_block_entities" => "chunk loading",
		".chunk.send" => "chunk sending", ".chunk.prepare" => "chunk sending"
	];
	private const PREFIXES = [
		"plugin.tasks." => "scheduler", "plugin." => "plugins", "block_entity." => "tiles", "block.hoppers" => "hoppers", "redstone." => "redstone",
		"entity." => "entities", "player.move" => "entities", "player.near_entities" => "entities", "player.chunk_" => "chunk sending",
		"network.compress" => "compression", "network.decompress" => "compression", "network." => "network",
		"server.gc" => "gc", "runtime.gc" => "gc", "server.memory" => "gc", "server.scheduler" => "scheduler",
		"server.async_results" => "async results", "worker.complete." => "async results", "event." => "events",
		"command." => "commands", "server.commands" => "commands", "player.commands" => "commands", "permissions." => "permissions",
		"player.data_" => "player data", "server.interrupts" => "interrupts", "server.notifier." => "interrupts"
	];

	/** @var Capture|null */
	private ?array $capture = null;
	/** @var Stats|null */
	private ?array $stats = null;

	/** @internal */
	public function __construct(private readonly PulseContext $context, string $threadName, int $durationNs = 0, int $spikeThresholdNs = 0, int $maxSpikes = 32){
		$this->context->start($threadName, hrtime(true), $durationNs, $spikeThresholdNs, $maxSpikes);
	}

	public function isRecording() : bool{
		return $this->capture === null && $this->context->recording;
	}

	public function stop() : void{
		if($this->capture === null){
			$this->context->stop(hrtime(true));
			$this->stats = $this->context->stats();
			$this->capture = $this->context->capture();
		}
	}

	/** @return Capture */
	public function getCapture() : array{
		if(!$this->context->recording){
			$this->stop();
		}
		return $this->capture ?? $this->context->capture();
	}

	/** @return Stats */
	public function getStats() : array{
		if(!$this->context->recording){ $this->stop(); }
		return $this->stats ?? $this->context->stats();
	}

	/** @return list<TopZone> */
	public function getTopZones(int $limit = 5) : array{
		if($limit < 1 || $limit > 50){ throw new \InvalidArgumentException("Pulse top limit must be 1-50"); }
		$capture = $this->getCapture();
		$self = $calls = $max = [];
		foreach($capture["nodes"] as $node){
			if($node[3] === 0){ continue; }
			$id = $node[1];
			$self[$id] = ($self[$id] ?? 0) + $node[5];
			$calls[$id] = ($calls[$id] ?? 0) + $node[3];
			$max[$id] = max($max[$id] ?? 0, $node[6]);
		}
		arsort($self, SORT_NUMERIC);
		$top = [];
		foreach(array_slice($self, 0, $limit, true) as $id => $time){
			$top[] = ["name" => $capture["zones"][$id], "calls" => $calls[$id], "self_ns" => $time, "max_ns" => $max[$id]];
		}
		return $top;
	}

	/** @return list<SpikeSummary> */
	public function getSpikes(int $limit = 5) : array{
		if($limit < 1 || $limit > 50){ throw new \InvalidArgumentException("Pulse spike limit must be 1-50"); }
		$capture = $this->getCapture();
		$spikes = $capture["spikes"];
		usort($spikes, static fn(array $a, array $b) : int => $b["tick"][2] === $a["tick"][2] ? $b["tick"][0] <=> $a["tick"][0] : $b["tick"][2] <=> $a["tick"][2]);
		$result = [];
		$zoneCategories = null;
		foreach(array_slice($spikes, 0, $limit) as $spike){
			$name = null;
			$self = 0;
			$costs = [];
			foreach($spike["nodes"] as $node){
				$zone = $capture["nodes"][$node[0] - 1][1];
				$cost = $costs[$zone] = ($costs[$zone] ?? 0) + $node[3];
				if($cost > $self){
					$self = $cost;
					$name = $capture["zones"][$zone];
				}
			}
			[$categories, $unattributed] = self::breakdown($spike, $capture, $zoneCategories ??= self::categories($capture));
			$result[] = ["tick_id" => $spike["tick"][0], "offset_ns" => $spike["tick"][1], "duration_ns" => $spike["tick"][2], "zone" => $name, "self_ns" => $self,
				"categories" => array_slice(self::sortCosts($categories), 0, 3), "unattributed_ns" => $unattributed];
		}
		return $result;
	}

	/**
	 * Sums retained slow ticks by category. Unattributed time ran inside the tick but outside every child zone.
	 * @return SpikeBreakdown
	 */
	public function getSpikeBreakdown() : array{
		$capture = $this->getCapture();
		$zoneCategories = self::categories($capture);
		$totals = [];
		$duration = $unattributed = 0;
		foreach($capture["spikes"] as $spike){
			[$categories, $missing] = self::breakdown($spike, $capture, $zoneCategories);
			foreach(Utils::stringifyKeys($categories) as $category => $cost){ $totals[$category] = ($totals[$category] ?? 0) + $cost; }
			$duration += $spike["tick"][2];
			$unattributed += $missing;
		}
		return ["spikes" => count($capture["spikes"]), "duration_ns" => $duration, "categories" => self::sortCosts($totals), "unattributed_ns" => $unattributed];
	}

	/**
	 * Session self time by category, including work outside ticks.
	 * @return list<CategoryCost>
	 */
	public function getCategories() : array{
		$capture = $this->getCapture();
		$zoneCategories = self::categories($capture);
		$totals = [];
		foreach($capture["nodes"] as $node){
			if($node[5] > 0){
				$category = $zoneCategories[$node[1]];
				$totals[$category] = ($totals[$category] ?? 0) + $node[5];
			}
		}
		return self::sortCosts($totals);
	}

	/**
	 * Plugin-owned zones grouped by plugin. Total time counts each plugin's outermost callbacks, so nested callbacks of
	 * the same plugin are not counted twice; it includes server work and other plugins' handlers they trigger. Self time
	 * excludes every nested measured zone and never overlaps between plugins.
	 * @return list<PluginSummary>
	 */
	public function getPlugins(int $limit = 5) : array{
		if($limit < 1 || $limit > 50){ throw new \InvalidArgumentException("Pulse plugin limit must be 1-50"); }
		$capture = $this->getCapture();
		$owners = [];
		foreach($capture["zone_owners"] ?? [] as [$zone, $owner]){ $owners[$zone] = $owner; }
		if($owners === []){ return []; }
		$nodes = $capture["nodes"];
		$plugins = [];
		foreach($nodes as $node){
			$owner = $owners[$node[1]] ?? null;
			if($owner === null || $node[3] === 0){ continue; }
			$plugin = $plugins[$owner] ?? ["name" => $owner, "calls" => 0, "total_ns" => 0, "self_ns" => 0, "max_ns" => 0, "tick_share" => null];
			$plugin["self_ns"] += $node[5];
			$parent = $node[2];
			while($parent !== 0 && ($parentNode = $nodes[$parent - 1] ?? null) !== null && ($owners[$parentNode[1]] ?? null) !== $owner){
				$parent = $parentNode[2];
			}
			if($parent === 0){
				$plugin["calls"] += $node[3];
				$plugin["total_ns"] += $node[4];
				$plugin["max_ns"] = max($plugin["max_ns"], $node[6]);
			}
			$plugins[$owner] = $plugin;
		}
		uasort($plugins, static fn(array $a, array $b) : int => $b["total_ns"] === $a["total_ns"] ? strcmp($a["name"], $b["name"]) : $b["total_ns"] <=> $a["total_ns"]);
		$result = [];
		foreach(array_slice($plugins, 0, $limit) as $plugin){
			$plugin["tick_share"] = $capture["tick_total_ns"] === 0 ? null : $plugin["total_ns"] / $capture["tick_total_ns"];
			$result[] = $plugin;
		}
		return $result;
	}

	/** @return NetworkSummary */
	public function getNetwork() : array{
		$network = $this->getCapture()["network"] ?? PulseNetwork::emptyCapture(0);
		$sums = [PulseNetwork::BATCHES => 0, PulseNetwork::PACKETS => 0, PulseNetwork::RECEIVED_BYTES => 0, PulseNetwork::DECOMPRESSED_BYTES => 0,
			PulseNetwork::DECODED => 0, PulseNetwork::DECODE_FAILED => 0, PulseNetwork::PLUGIN_CANCELLED => 0, PulseNetwork::STATE_DROPPED => 0, PulseNetwork::REPEATED_DROPPED => 0];
		foreach($network["windows"] as $window){
			foreach($sums as $metric => $sum){
				$sums[$metric] = $sum + $window[$metric];
			}
		}
		return [
			"batches" => $sums[PulseNetwork::BATCHES], "packets" => $sums[PulseNetwork::PACKETS],
			"received_bytes" => $sums[PulseNetwork::RECEIVED_BYTES], "decompressed_bytes" => $sums[PulseNetwork::DECOMPRESSED_BYTES],
			"decoded" => $sums[PulseNetwork::DECODED], "decode_failed" => $sums[PulseNetwork::DECODE_FAILED],
			"plugin_cancelled" => $sums[PulseNetwork::PLUGIN_CANCELLED], "state_dropped" => $sums[PulseNetwork::STATE_DROPPED],
			"repeated_dropped" => $sums[PulseNetwork::REPEATED_DROPPED],
			"enforcement_events" => count($network["events"]) + $network["events_dropped"], "enforcement_events_dropped" => $network["events_dropped"],
			"windows_dropped" => $network["windows_dropped"], "transports" => $network["transports"] ?? []
		];
	}

	public static function category(string $zone) : string{
		if($zone === "server.tick"){ return self::UNATTRIBUTED; }
		if(str_starts_with($zone, "world.")){
			foreach(Utils::stringifyKeys(self::WORLD_SUFFIXES) as $suffix => $category){
				if(str_ends_with($zone, $suffix)){ return $category; }
			}
			return "world";
		}
		foreach(Utils::stringifyKeys(self::PREFIXES) as $prefix => $category){
			if(str_starts_with($zone, $prefix)){ return $category; }
		}
		return "other";
	}

	/**
	 * @param Capture $capture
	 * @return list<string> category per zone ID
	 */
	private static function categories(array $capture) : array{
		$owned = [];
		foreach($capture["zone_owners"] ?? [] as [$zone]){ $owned[$zone] = true; }
		$categories = [];
		foreach($capture["zones"] as $id => $name){
			$categories[] = isset($owned[$id]) ? "plugins" : self::category($name);
		}
		return $categories;
	}

	/**
	 * @param Spike        $spike
	 * @param Capture      $capture
	 * @param list<string> $categories
	 * @return array{array<string, int>, int}
	 */
	private static function breakdown(array $spike, array $capture, array $categories) : array{
		$costs = [];
		$measured = $unattributed = 0;
		foreach($spike["nodes"] as $row){
			$category = $categories[$capture["nodes"][$row[0] - 1][1]];
			if($category === self::UNATTRIBUTED){
				$unattributed += $row[3];
			}else{
				$costs[$category] = ($costs[$category] ?? 0) + $row[3];
				$measured += $row[3];
			}
		}
		return [$costs, $unattributed + max(0, $spike["tick"][2] - $measured - $unattributed)];
	}

	/**
	 * @param array<string, int> $costs
	 * @return list<CategoryCost>
	 */
	private static function sortCosts(array $costs) : array{
		$rows = [];
		foreach(Utils::stringifyKeys($costs) as $category => $cost){ $rows[] = [$category, $cost]; }
		usort($rows, static fn(array $a, array $b) : int => $b[1] === $a[1] ? strcmp($a[0], $b[0]) : $b[1] <=> $a[1]);
		return $rows;
	}

	/** @param array<string, mixed> $metadata */
	public function getReport(array $metadata = []) : PulseReport{
		return PulseReport::create([$this->getCapture()], $metadata);
	}
}
