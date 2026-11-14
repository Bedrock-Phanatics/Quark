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

use function max;
use const PHP_INT_MAX;

/**
 * @internal Thread-local async task accounting. The main thread counts submissions and collected results; each worker
 * counts the tasks it ran, so no task carries extra shared state.
 * @phpstan-type WorkerCapture array{submitted: int, completed: int, queue_peak: int, turnaround_total_ns: int, turnaround_max_ns: int, pickup_total_ns: int, pickup_max_ns: int, ran: int, wait_total_ns: int, wait_max_ns: int, run_total_ns: int, run_max_ns: int, heap_bytes: int, heap_peak_bytes: int}
 */
final class PulseWorkers{
	private int $submitted = 0;
	private int $completed = 0;
	private int $queuePeak = 0;
	private int $turnaroundTotal = 0;
	private int $turnaroundMax = 0;
	private int $pickupTotal = 0;
	private int $pickupMax = 0;
	private int $ran = 0;
	private int $waitTotal = 0;
	private int $waitMax = 0;
	private int $runTotal = 0;
	private int $runMax = 0;
	private int $heap = 0;
	private int $heapPeak = 0;

	public function __construct(private readonly int $started){}

	public function submitted(int $queueDepth) : void{
		++$this->submitted;
		if($queueDepth > $this->queuePeak){ $this->queuePeak = $queueDepth; }
	}

	/** Results of tasks queued before this session started are ignored, so restarts never mix sessions. */
	public function collected(int $queued, int $finished, int $now) : void{
		if($queued < $this->started || $this->completed === $this->submitted){
			return;
		}
		++$this->completed;
		$turnaround = max(0, $now - $queued);
		$pickup = max(0, $now - $finished);
		$this->turnaroundTotal = self::add($this->turnaroundTotal, $turnaround);
		$this->pickupTotal = self::add($this->pickupTotal, $pickup);
		$this->turnaroundMax = max($this->turnaroundMax, $turnaround);
		$this->pickupMax = max($this->pickupMax, $pickup);
	}

	/** @param int $queued submission stamp, or 1 when the task was queued before recording */
	public function ran(int $queued, int $started, int $finished, int $heap) : void{
		if($queued <= 1){
			return;
		}
		++$this->ran;
		$wait = max(0, $started - $queued);
		$run = max(0, $finished - $started);
		$this->waitTotal = self::add($this->waitTotal, $wait);
		$this->runTotal = self::add($this->runTotal, $run);
		$this->waitMax = max($this->waitMax, $wait);
		$this->runMax = max($this->runMax, $run);
		$this->heap = max(0, $heap);
		$this->heapPeak = max($this->heapPeak, $this->heap);
	}

	private static function add(int $total, int $value) : int{
		return $value > PHP_INT_MAX - $total ? PHP_INT_MAX : $total + $value;
	}

	/** @return WorkerCapture */
	public function capture() : array{
		return [
			"submitted" => $this->submitted, "completed" => $this->completed, "queue_peak" => $this->queuePeak,
			"turnaround_total_ns" => $this->turnaroundTotal, "turnaround_max_ns" => $this->turnaroundMax,
			"pickup_total_ns" => $this->pickupTotal, "pickup_max_ns" => $this->pickupMax,
			"ran" => $this->ran, "wait_total_ns" => $this->waitTotal, "wait_max_ns" => $this->waitMax,
			"run_total_ns" => $this->runTotal, "run_max_ns" => $this->runMax,
			"heap_bytes" => $this->heap, "heap_peak_bytes" => $this->heapPeak
		];
	}
}
