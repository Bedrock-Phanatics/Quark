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

use function ksort;
use function max;
use const PHP_INT_MAX;
use const SORT_NUMERIC;

/**
 * @internal Main-thread async pool accounting, fed from task timestamps when results are collected.
 * @phpstan-type WorkerRow array{int, int, int, int, int}
 * @phpstan-type WorkerCapture array{submitted: int, completed: int, queue_peak: int, wait_total_ns: int, wait_max_ns: int, run_total_ns: int, run_max_ns: int, delay_total_ns: int, delay_max_ns: int, workers: list<WorkerRow>}
 */
final class PulseWorkers{
	public const MAX_WORKERS = 128;

	private int $submitted = 0;
	private int $completed = 0;
	private int $queuePeak = 0;
	private int $waitTotal = 0;
	private int $waitMax = 0;
	private int $runTotal = 0;
	private int $runMax = 0;
	private int $delayTotal = 0;
	private int $delayMax = 0;
	/** @var array<int, array{int, int, int, int}> worker => completed, run, heap, heap peak */
	private array $workers = [];

	public function __construct(public readonly int $started){}

	public function submitted(int $queueDepth) : void{
		++$this->submitted;
		if($queueDepth > $this->queuePeak){ $this->queuePeak = $queueDepth; }
	}

	/** Tasks queued before this session started are ignored, so a restart never mixes sessions. */
	public function completed(int $worker, int $queued, int $started, int $finished, int $heap, int $now) : void{
		if($queued < $this->started || $started === 0 || $this->completed === $this->submitted){
			return;
		}
		$wait = max(0, $started - $queued);
		$run = max(0, $finished - $started);
		$delay = max(0, $now - max($finished, $started));
		++$this->completed;
		$this->waitTotal = self::add($this->waitTotal, $wait);
		$this->runTotal = self::add($this->runTotal, $run);
		$this->delayTotal = self::add($this->delayTotal, $delay);
		$this->waitMax = max($this->waitMax, $wait);
		$this->runMax = max($this->runMax, $run);
		$this->delayMax = max($this->delayMax, $delay);
		if($worker >= 0 && $worker < self::MAX_WORKERS){
			$row = $this->workers[$worker] ?? [0, 0, 0, 0];
			$heap = max(0, $heap);
			$this->workers[$worker] = [$row[0] + 1, self::add($row[1], $run), $heap, max($row[3], $heap)];
		}
	}

	private static function add(int $total, int $value) : int{
		return $value > PHP_INT_MAX - $total ? PHP_INT_MAX : $total + $value;
	}

	/** @return WorkerCapture */
	public function capture() : array{
		$workers = $this->workers;
		ksort($workers, SORT_NUMERIC);
		$rows = [];
		foreach($workers as $id => $row){
			$rows[] = [$id, $row[0], $row[1], $row[2], $row[3]];
		}
		return [
			"submitted" => $this->submitted, "completed" => $this->completed, "queue_peak" => $this->queuePeak,
			"wait_total_ns" => $this->waitTotal, "wait_max_ns" => $this->waitMax,
			"run_total_ns" => $this->runTotal, "run_max_ns" => $this->runMax,
			"delay_total_ns" => $this->delayTotal, "delay_max_ns" => $this->delayMax,
			"workers" => $rows
		];
	}
}
