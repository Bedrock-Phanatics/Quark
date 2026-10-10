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

namespace quark\pulse\internal;

use quark\pulse\PulseZone;
use function array_shift;
use function count;
use function hrtime;
use function min;
use function preg_match;
use function strlen;

/**
 * @internal Thread-local; never share between workers.
 * @phpstan-type NodeRow array{int, int, int, int, int, int, int}
 * @phpstan-type TickRow array{int, int, int}
 * @phpstan-type SpikeRow array{int, int, int, int}
 * @phpstan-type Spike array{tick: TickRow, nodes: list<SpikeRow>}
 * @phpstan-import-type NetworkCapture from PulseNetwork
 * @phpstan-type Capture array{thread: string, started_ns: int, ended_ns: int, recording: bool, zones: list<string>, nodes: list<NodeRow>, active_ticks: list<int>, unbalanced_scopes: int, dropped_scopes: int, ticks: list<TickRow>, tick_count: int, tick_total_ns: int, tick_max_ns: int, unbalanced_ticks: int, spikes: list<Spike>, spikes_dropped: int, spike_threshold_ns: int, max_spikes: int, tick_capacity: int, duration_ns: int, network?: NetworkCapture}
 * @phpstan-type Stats array{recording: bool, elapsed_ns: int, duration_ns: int, spike_threshold_ns: int, tick_count: int, tick_total_ns: int, tick_max_ns: int, retained_spikes: int, spikes_dropped: int, dropped_scopes: int, unbalanced_scopes: int, unbalanced_ticks: int}
 */
final class PulseContext{
	public const MAX_SPIKE_ROWS = 65536;
	private(set) bool $recording = false;
	/** @var array<string, PulseZone> */
	private array $zones = [];
	/** @var list<string> */
	private array $names = [];
	/** @var list<PulseNode> */
	private array $nodes;
	private PulseNode $current;
	private int $depth = 0;
	private int $sequence = 0;
	private int $firstScope = 1;
	private int $unbalanced = 0;
	private int $dropped = 0;
	private int $started = 0;
	private int $ended = 0;
	private string $threadName = "main";
	private int $duration = 0;
	private int $spikeThreshold = 0;
	private int $maxSpikes = 32;
	private int $tickStarted = -1;
	private int $tickCount = 0;
	private int $tickTotal = 0;
	private int $tickMax = 0;
	private int $tickCursor = 0;
	private int $unbalancedTicks = 0;
	/** @var array<int, int> */
	private array $tickIds = [];
	/** @var array<int, int> */
	private array $tickStarts = [];
	/** @var array<int, int> */
	private array $tickDurations = [];
	/** @var array<int, PulseNode> */
	private array $touched = [];
	private int $touchedCount = 0;
	/** @var list<Spike> */
	private array $spikes = [];
	private int $spikeRows = 0;
	private int $spikesDropped = 0;
	private ?PulseNetwork $network = null;

	public function network() : PulseNetwork{
		return $this->network ??= new PulseNetwork($this->started);
	}

	public function getNetworkTickId() : ?int{
		return $this->recording && $this->tickStarted >= 0 ? $this->tickCount + 1 : null;
	}

	public function __construct(
		private readonly int $maxZones = 4096,
		private readonly int $maxNodes = 16384,
		private readonly int $maxDepth = 256,
		private readonly int $maxTicks = 1200
	){
		if($maxZones < 1 || $maxZones > 4096 || $maxNodes < 1 || $maxNodes > 16384 || $maxDepth < 1 || $maxDepth > 256 || $maxTicks < 1 || $maxTicks > 4096){
			throw new \InvalidArgumentException("Invalid Pulse capture limits");
		}
		$root = new PulseNode(0, -1, 0);
		$this->nodes = [$root];
		$this->current = $root;
	}

	public function zone(string $name) : PulseZone{
		if(isset($this->zones[$name])){
			return $this->zones[$name];
		}
		if($name === "" || strlen($name) > 256 || preg_match('//u', $name) !== 1){
			throw new \InvalidArgumentException("Pulse zone names must be 1-256 bytes of UTF-8");
		}
		if(count($this->names) >= $this->maxZones){
			throw new \LengthException("Pulse zone registration limit reached");
		}
		$id = count($this->names);
		$this->names[] = $name;
		return $this->zones[$name] = new PulseZone($this, $id, $name);
	}

	public function start(string $threadName, int $now, int $durationNs = 0, int $spikeThresholdNs = 0, int $maxSpikes = 32) : void{
		if($this->recording){
			throw new \LogicException("Pulse is already recording in this thread");
		}
		if($threadName === "" || strlen($threadName) > 256 || preg_match('//u', $threadName) !== 1){
			throw new \InvalidArgumentException("Invalid Pulse thread name");
		}
		if($durationNs < 0 || $durationNs > 86400000000000 || $spikeThresholdNs < 0 || $spikeThresholdNs > 60000000000 || $maxSpikes < 1 || $maxSpikes > 128){
			throw new \InvalidArgumentException("Invalid Pulse session options");
		}
		foreach($this->nodes as $node){
			$node->reset();
		}
		$this->threadName = $threadName;
		$this->current = $this->nodes[0];
		$this->depth = $this->unbalanced = $this->dropped = $this->ended = 0;
		$this->firstScope = $this->sequence + 1;
		$this->started = $now;
		$this->duration = $durationNs;
		$this->spikeThreshold = $spikeThresholdNs;
		$this->maxSpikes = $maxSpikes;
		$this->tickStarted = -1;
		$this->tickCount = $this->tickTotal = $this->tickMax = $this->tickCursor = $this->unbalancedTicks = 0;
		$this->touchedCount = $this->spikeRows = $this->spikesDropped = 0;
		$this->spikes = [];
		$this->network = null;
		$this->recording = true;
	}

	public function reset() : void{
		if($this->recording){ throw new \LogicException("Cannot clear a recording Pulse context"); }
		$root = new PulseNode(0, -1, 0);
		$this->nodes = [$root];
		$this->current = $root;
		$this->tickIds = $this->tickStarts = $this->tickDurations = $this->touched = [];
		$this->start("main", 0);
		$this->stop(0);
	}

	public function begin(int $zone, int $now) : int{
		if(!$this->recording){
			return 0;
		}
		if($this->depth === $this->maxDepth){
			++$this->dropped;
			return 0;
		}
		$parent = $this->current;
		$node = $parent->children[$zone] ?? null;
		if($node === null){
			if(count($this->nodes) - 1 >= $this->maxNodes){
				++$this->dropped;
				return 0;
			}
			$id = count($this->nodes);
			$node = new PulseNode($id, $zone, $parent->id);
			$this->nodes[] = $parent->children[$zone] = $node;
		}
		++$this->depth;
		$this->current = $node;
		$node->started = $now;
		$node->childTime = 0;
		return $node->scope = ++$this->sequence;
	}

	public function end(int $zone, int $scope, int $now) : void{
		// Old-session tokens cannot close new scopes.
		if(!$this->recording || $scope < $this->firstScope){
			return;
		}
		$node = $this->current;
		if($node->scope !== $scope || $node->zone !== $zone){
			while($node->id !== 0 && ($node->scope !== $scope || $node->zone !== $zone)){
				$node = $this->nodes[$node->parent];
			}
			if($node->id === 0){
				++$this->unbalanced;
				return;
			}
			while($this->current !== $node){
				++$this->unbalanced;
				$this->close($now);
			}
		}
		--$this->depth;
		$node = $this->current;
		$this->current = $this->nodes[$node->parent];
		$elapsed = $now - $node->started;
		if($elapsed < 0){
			$elapsed = 0;
		}
		$self = $elapsed - $node->childTime;
		if($this->tickStarted >= 0 && $node->lastTick !== $this->tickCount + 1){
			$node->lastTick = $this->tickCount + 1;
			++$node->activeTicks;
			if($this->spikeThreshold > 0){
				$node->tickCalls = $node->calls;
				$node->tickTotal = $node->total;
				$node->tickSelf = $node->self;
				$this->touched[$this->touchedCount++] = $node;
			}
		}
		++$node->calls;
		$node->total += $elapsed;
		$node->self += $self > 0 ? $self : 0;
		if($elapsed > $node->max){
			$node->max = $elapsed;
		}
		if($this->depth > 0){
			$this->current->childTime += $elapsed;
		}
	}

	private function close(int $now) : void{
		$node = $this->current;
		$this->end($node->zone, $node->scope, $now);
	}

	public function stop(int $now) : void{
		if(!$this->recording){
			return;
		}
		if($this->tickStarted >= 0){
			$this->finishTick($now);
		}else{
			$this->closeUnbalanced($now);
		}
		$this->ended = $now;
		$this->recording = false;
		$this->network?->stop();
	}

	private function closeUnbalanced(int $now) : void{
		while($this->depth > 0){
			++$this->unbalanced;
			$this->close($now);
		}
	}

	public function beginTick(int $now) : void{
		if(!$this->recording){
			return;
		}
		if($this->tickStarted >= 0){
			++$this->unbalancedTicks;
			$this->finishTick($now);
		}
		$this->checkDuration($now);
		if($this->recording){
			$this->closeUnbalanced($now);
			$this->tickStarted = $now;
			$this->touchedCount = 0;
		}
	}

	public function endTick(int $now) : void{
		if(!$this->recording){
			return;
		}
		if($this->tickStarted >= 0){
			$this->finishTick($now);
		}else{
			++$this->unbalancedTicks;
		}
		$this->checkDuration($now);
	}

	public function checkDuration(int $now) : void{
		if($this->recording && $this->duration > 0 && $now - $this->started >= $this->duration){
			$this->stop($now);
		}
	}

	private function finishTick(int $now) : void{
		$this->closeUnbalanced($now);
		$duration = $now - $this->tickStarted;
		if($duration < 0){
			$duration = 0;
		}
		$id = ++$this->tickCount;
		$offset = $this->tickStarted - $this->started;
		$offset = $offset > 0 ? $offset : 0;
		$this->tickIds[$this->tickCursor] = $id;
		$this->tickStarts[$this->tickCursor] = $offset;
		$this->tickDurations[$this->tickCursor] = $duration;
		$this->tickCursor = ($this->tickCursor + 1) % $this->maxTicks;
		$this->tickTotal += $duration;
		if($duration > $this->tickMax){
			$this->tickMax = $duration;
		}
		$this->tickStarted = -1;
		if($this->spikeThreshold > 0 && $duration > $this->spikeThreshold){
			while(count($this->spikes) >= $this->maxSpikes || $this->spikeRows + $this->touchedCount > self::MAX_SPIKE_ROWS){
				$old = array_shift($this->spikes);
				if($old === null){
					break;
				}
				$this->spikeRows -= count($old["nodes"]);
				++$this->spikesDropped;
			}
			$rows = [];
			for($i = 0; $i < $this->touchedCount; ++$i){
				$node = $this->touched[$i];
				$rows[] = [$node->id, $node->calls - $node->tickCalls, $node->total - $node->tickTotal, $node->self - $node->tickSelf];
			}
			$this->spikes[] = ["tick" => [$id, $offset, $duration], "nodes" => $rows];
			$this->spikeRows += count($rows);
		}
	}

	/** @return Stats */
	public function stats() : array{
		return [
			"recording" => $this->recording,
			"elapsed_ns" => ($this->recording ? (int) hrtime(true) : $this->ended) - $this->started,
			"duration_ns" => $this->duration,
			"spike_threshold_ns" => $this->spikeThreshold,
			"tick_count" => $this->tickCount,
			"tick_total_ns" => $this->tickTotal,
			"tick_max_ns" => $this->tickMax,
			"retained_spikes" => count($this->spikes),
			"spikes_dropped" => $this->spikesDropped,
			"dropped_scopes" => $this->dropped,
			"unbalanced_scopes" => $this->unbalanced,
			"unbalanced_ticks" => $this->unbalancedTicks
		];
	}

	/** @return Capture */
	public function capture() : array{
		$ended = $this->recording ? (int) hrtime(true) : $this->ended;
		$rows = [];
		$activeTicks = [];
		foreach($this->nodes as $node){
			if($node->id !== 0){
				$rows[] = [$node->id, $node->zone, $node->parent, $node->calls, $node->total, $node->self, $node->max];
				$activeTicks[] = $node->activeTicks;
			}
		}
		$ticks = [];
		$retained = min($this->tickCount, $this->maxTicks);
		$first = $this->tickCount >= $this->maxTicks ? $this->tickCursor : 0;
		for($i = 0; $i < $retained; ++$i){
			$index = ($first + $i) % $this->maxTicks;
			$ticks[] = [$this->tickIds[$index], $this->tickStarts[$index], $this->tickDurations[$index]];
		}
		return [
			"thread" => $this->threadName,
			"started_ns" => $this->started,
			"ended_ns" => $ended,
			"recording" => $this->recording,
			"zones" => $this->names,
			"nodes" => $rows,
			"active_ticks" => $activeTicks,
			"unbalanced_scopes" => $this->unbalanced,
			"dropped_scopes" => $this->dropped,
			"ticks" => $ticks,
			"tick_count" => $this->tickCount,
			"tick_total_ns" => $this->tickTotal,
			"tick_max_ns" => $this->tickMax,
			"unbalanced_ticks" => $this->unbalancedTicks,
			"spikes" => $this->spikes,
			"spikes_dropped" => $this->spikesDropped,
			"spike_threshold_ns" => $this->spikeThreshold,
			"max_spikes" => $this->maxSpikes,
			"tick_capacity" => $this->maxTicks,
			"duration_ns" => $this->duration,
			"network" => $this->network?->capture($ended - $this->started) ?? PulseNetwork::emptyCapture($ended - $this->started)
		];
	}
}
