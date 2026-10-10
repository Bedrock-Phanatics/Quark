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

use function array_merge;
use function array_slice;
use function count;
use function hrtime;
use function intdiv;
use function max;
use const PHP_INT_MAX;

/**
 * @internal Thread-local; IDs and storage belong to one capture.
 * @phpstan-type SessionRow array{int, int, int|null, string, string, int|null}
 * @phpstan-type EventRow array{int, int|null, int|null, int|null, string, int|null, int|null, string, string}
 * @phpstan-type WindowRow array{int, int|null, int, int, int, int, int, int, int, int, int}
 * @phpstan-type NetworkCapture array{coverage: array{from_ns: int, to_ns: int, window_ns: int, receive_layer: string, session_limit: int, event_limit: int, window_limit: int}, sessions: list<SessionRow>, events: list<EventRow>, windows: list<WindowRow>, sessions_dropped: int, events_dropped: int, windows_dropped: int, counter_overflows: int}
 */
final class PulseNetwork{
	public const MAX_SESSIONS = 1024;
	public const MAX_EVENTS = 4096;
	public const MAX_WINDOWS = 4096;
	public const WINDOW_NS = 1000000000;
	public const BATCHES = 2;
	public const PACKETS = 3;
	public const RECEIVED_BYTES = 4;
	public const DECOMPRESSED_BYTES = 5;
	public const DECODED = 6;
	public const DECODE_FAILED = 7;
	public const PLUGIN_CANCELLED = 8;
	public const STATE_DROPPED = 9;
	public const REPEATED_DROPPED = 10;
	public const PHASES = ["session_start", "login", "awaiting_async", "handshake", "resource_packs", "pre_spawn", "spawn_response", "in_game", "death", "closed", "unknown"];
	public const REASONS = ["rate.batch", "rate.packet", "batch.packet_limit", "batch.empty", "batch.malformed", "packet.unknown", "packet.direction", "packet.state", "packet.malformed", "packet.handler_validation", "compression.algorithm", "decompression.malformed", "decompression.limit", "encryption.invalid", "packet.bad_packet_disconnect", "transport.header"];
	public const ACTIONS = ["drop_packet", "drop_batch", "reject_batch", "disconnect"];

	private static int $nextGeneration = 0;
	public readonly int $generation;
	public bool $recording = true;
	/** @var list<SessionRow> */
	private array $sessions = [];
	/** @var list<EventRow> */
	private array $events = [];
	/** @var list<WindowRow> */
	private array $windows = [];
	/** @var array<int, int> */
	private array $currentWindows = [];
	private int $bucket = -1;
	private int $eventCursor = 0;
	private int $windowCursor = 0;
	private int $sessionsDropped = 0;
	private int $eventsDropped = 0;
	private int $windowsDropped = 0;
	private int $counterOverflows = 0;

	public function __construct(private readonly int $started){
		$this->generation = ++self::$nextGeneration;
	}

	private function offset(?int $now) : int{
		return max(0, ($now ?? (int) hrtime(true)) - $this->started);
	}

	public function session(?int $protocol, string $phase, ?int $now = null) : int{
		if(!$this->recording){ return 0; }
		if(count($this->sessions) === self::MAX_SESSIONS){
			++$this->sessionsDropped;
			return 0;
		}
		$id = count($this->sessions) + 1;
		$offset = $this->offset($now);
		$this->sessions[] = [$id, $offset, $protocol, $phase, $phase, $phase === "closed" ? $offset : null];
		return $id;
	}

	public function updateSession(int $id, ?int $protocol, string $phase, ?int $now = null) : void{
		if(!$this->recording || $id === 0 || !isset($this->sessions[$id - 1])){ return; }
		$this->sessions[$id - 1][2] = $protocol;
		$this->sessions[$id - 1][4] = $phase;
		if($phase === "closed"){ $this->sessions[$id - 1][5] = $this->offset($now); }
	}

	public function window(int $id, ?int $now = null) : int{
		if(!$this->recording){ return -1; }
		$bucket = intdiv($this->offset($now), self::WINDOW_NS);
		if($bucket !== $this->bucket){
			$this->bucket = $bucket;
			$this->currentWindows = [];
		}
		if(isset($this->currentWindows[$id])){ return $this->currentWindows[$id]; }
		$sequence = $this->windowCursor++;
		$index = $sequence % self::MAX_WINDOWS;
		if(count($this->windows) === self::MAX_WINDOWS){ ++$this->windowsDropped; }
		$this->windows[$index] = [$bucket * self::WINDOW_NS, $id === 0 ? null : $id, 0, 0, 0, 0, 0, 0, 0, 0, 0];
		return $this->currentWindows[$id] = $sequence;
	}

	/** @param int<2, 10> $metric */
	public function count(int $window, int $metric, int $amount = 1) : void{
		if(!$this->recording){ return; }
		// A plugin callback may outlive the retained window.
		if($window < $this->windowCursor - self::MAX_WINDOWS){ return; }
		$window %= self::MAX_WINDOWS;
		$value = $this->windows[$window][$metric];
		if($amount > PHP_INT_MAX - $value){
			$this->windows[$window][$metric] = PHP_INT_MAX;
			++$this->counterOverflows;
		}else{
			$this->windows[$window][$metric] = $value + $amount;
		}
	}

	public function event(int $id, ?int $packet, string $phase, string $reason, string $action, ?int $tick = null, ?int $observed = null, ?int $limit = null, ?int $now = null) : void{
		if(!$this->recording){ return; }
		if(count($this->events) === self::MAX_EVENTS){ ++$this->eventsDropped; }
		$this->events[$this->eventCursor] = [$this->offset($now), $tick, $id === 0 ? null : $id, $packet, $reason, $observed, $limit, $action, $phase];
		$this->eventCursor = ($this->eventCursor + 1) % self::MAX_EVENTS;
	}

	/** @return NetworkCapture */
	public function capture(int $elapsed) : array{
		$data = self::emptyCapture($elapsed);
		$data["sessions"] = $this->sessions;
		$data["events"] = count($this->events) === self::MAX_EVENTS ? array_merge(array_slice($this->events, $this->eventCursor), array_slice($this->events, 0, $this->eventCursor)) : $this->events;
		$cursor = $this->windowCursor % self::MAX_WINDOWS;
		$data["windows"] = count($this->windows) === self::MAX_WINDOWS ? array_merge(array_slice($this->windows, $cursor), array_slice($this->windows, 0, $cursor)) : $this->windows;
		$data["sessions_dropped"] = $this->sessionsDropped;
		$data["events_dropped"] = $this->eventsDropped;
		$data["windows_dropped"] = $this->windowsDropped;
		$data["counter_overflows"] = $this->counterOverflows;
		return $data;
	}

	/** @return NetworkCapture */
	public static function emptyCapture(int $elapsed) : array{
		return [
			"coverage" => ["from_ns" => 0, "to_ns" => $elapsed, "window_ns" => self::WINDOW_NS, "receive_layer" => "mcpe_batch", "session_limit" => self::MAX_SESSIONS, "event_limit" => self::MAX_EVENTS, "window_limit" => self::MAX_WINDOWS],
			"sessions" => [], "events" => [], "windows" => [],
			"sessions_dropped" => 0, "events_dropped" => 0, "windows_dropped" => 0, "counter_overflows" => 0
		];
	}
}
