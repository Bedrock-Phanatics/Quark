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
use function array_fill;
use function count;
use function hrtime;
use function max;
use function preg_match;
use function strlen;

/**
 * @internal Thread-local; never share between workers.
 * @phpstan-type NodeRow array{int, int, int, int, int, int, int}
 * @phpstan-type Capture array{thread: string, started_ns: int, ended_ns: int, recording: bool, zones: list<string>, nodes: list<NodeRow>, unbalanced_scopes: int, dropped_scopes: int}
 */
final class PulseContext{
	public bool $recording = false;
	/** @var array<string, PulseZone> */
	private array $zones = [];
	/** @var list<string> */
	private array $names = [];
	/** @var list<PulseNode> */
	private array $nodes;
	/** @var array<int, PulseNode> */
	private array $frames;
	/** @var array<int, int> */
	private array $starts;
	/** @var array<int, int> */
	private array $children;
	/** @var array<int, int> */
	private array $scopes;
	private int $depth = 0;
	private int $sequence = 0;
	private int $firstScope = 1;
	private int $unbalanced = 0;
	private int $dropped = 0;
	private int $started = 0;
	private int $ended = 0;
	private string $threadName = "main";

	public function __construct(
		private readonly int $maxZones = 4096,
		private readonly int $maxNodes = 16384,
		private readonly int $maxDepth = 256
	){
		if($maxZones < 1 || $maxZones > 4096 || $maxNodes < 1 || $maxNodes > 16384 || $maxDepth < 1 || $maxDepth > 256){
			throw new \InvalidArgumentException("Invalid Pulse capture limits");
		}
		$root = new PulseNode(0, -1, 0);
		$this->nodes = [$root];
		$this->frames = array_fill(0, $maxDepth, $root);
		$this->starts = $this->children = $this->scopes = array_fill(0, $maxDepth, 0);
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

	public function start(string $threadName, int $now) : void{
		if($this->recording){
			throw new \LogicException("Pulse is already recording in this thread");
		}
		if($threadName === "" || strlen($threadName) > 256 || preg_match('//u', $threadName) !== 1){
			throw new \InvalidArgumentException("Invalid Pulse thread name");
		}
		foreach($this->nodes as $node){
			$node->reset();
		}
		$this->threadName = $threadName;
		$this->depth = $this->unbalanced = $this->dropped = $this->ended = 0;
		$this->firstScope = $this->sequence + 1;
		$this->started = $now;
		$this->recording = true;
	}

	public function begin(int $zone, int $now) : int{
		if(!$this->recording){
			return 0;
		}
		if($this->depth === $this->maxDepth){
			++$this->dropped;
			return 0;
		}
		$parent = $this->depth === 0 ? $this->nodes[0] : $this->frames[$this->depth - 1];
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
		$depth = $this->depth++;
		$this->frames[$depth] = $node;
		$this->starts[$depth] = $now;
		$this->children[$depth] = 0;
		return $this->scopes[$depth] = ++$this->sequence;
	}

	public function end(int $zone, int $scope, int $now) : void{
		// Old-session tokens cannot close new scopes.
		if(!$this->recording || $scope < $this->firstScope){
			return;
		}
		$depth = $this->depth - 1;
		if($depth < 0 || $this->scopes[$depth] !== $scope || $this->frames[$depth]->zone !== $zone){
			for(; $depth >= 0; --$depth){
				if($this->scopes[$depth] === $scope && $this->frames[$depth]->zone === $zone){
					break;
				}
			}
			if($depth < 0){
				++$this->unbalanced;
				return;
			}
			while($this->depth - 1 > $depth){
				++$this->unbalanced;
				$this->close($now);
			}
		}
		$this->close($now);
	}

	private function close(int $now) : void{
		$depth = --$this->depth;
		$node = $this->frames[$depth];
		$elapsed = max(0, $now - $this->starts[$depth]);
		++$node->calls;
		$node->total += $elapsed;
		$node->self += max(0, $elapsed - $this->children[$depth]);
		$node->max = max($node->max, $elapsed);
		if($depth > 0){
			$this->children[$depth - 1] += $elapsed;
		}
	}

	public function stop(int $now) : void{
		if(!$this->recording){
			return;
		}
		while($this->depth > 0){
			++$this->unbalanced;
			$this->close($now);
		}
		$this->ended = $now;
		$this->recording = false;
	}

	/** @return Capture */
	public function capture() : array{
		$rows = [];
		foreach($this->nodes as $node){
			if($node->id !== 0){
				$rows[] = [$node->id, $node->zone, $node->parent, $node->calls, $node->total, $node->self, $node->max];
			}
		}
		return [
			"thread" => $this->threadName,
			"started_ns" => $this->started,
			"ended_ns" => $this->recording ? (int) hrtime(true) : $this->ended,
			"recording" => $this->recording,
			"zones" => $this->names,
			"nodes" => $rows,
			"unbalanced_scopes" => $this->unbalanced,
			"dropped_scopes" => $this->dropped
		];
	}
}
