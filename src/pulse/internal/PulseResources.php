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
use function file_get_contents;
use function function_exists;
use function getrusage;
use function is_readable;
use function max;
use function memory_get_usage;
use function preg_match;
use const PHP_INT_MAX;
use const PHP_OS_FAMILY;

/**
 * @internal Process CPU, process RSS and main-thread PHP memory, sampled only while recording.
 * @phpstan-type ResourceRow array{int, int, int|null, int|null, int, int, int|null, int|null}
 * @phpstan-type ResourceCapture array{samples: list<ResourceRow>, samples_dropped: int}
 * @phpstan-type ResourceStats array{cpu_percent: float|null, cpu_avg_percent: float|null, cpu_peak_percent: float|null, memory_bytes: int, memory_allocated_bytes: int, memory_peak_bytes: int, memory_growth_bytes: int, rss_bytes: int|null, rss_peak_bytes: int|null, rss_growth_bytes: int|null}
 */
final class PulseResources{
	public const INTERVAL_NS = 1000000000;
	public const MAX_SAMPLES = 1200;
	/** @var list<ResourceRow> */
	private array $samples = [];
	private int $cursor = 0;
	private int $dropped = 0;
	private int $previous;
	private ?int $user = null;
	private ?int $system = null;
	private readonly bool $procStatus;
	private int $initialMemory = 0;
	private ?int $initialRss = null;

	public function __construct(private readonly int $started){
		$this->previous = $started;
		$this->procStatus = PHP_OS_FAMILY === "Linux" && is_readable("/proc/self/status");
		$this->sample($started, true);
		$this->initialMemory = $this->samples[0][4];
		$this->initialRss = $this->samples[0][6];
	}

	/** @return array{int|null, int|null} current and peak resident set size since process start */
	private function rss() : array{
		$status = $this->procStatus ? @file_get_contents("/proc/self/status") : false;
		if($status === false){ return [null, null]; }
		$rss = preg_match('/^VmRSS:\s+(\d{1,15}) kB$/m', $status, $match) === 1 ? (int) $match[1] * 1024 : null;
		$peak = preg_match('/^VmHWM:\s+(\d{1,15}) kB$/m', $status, $match) === 1 ? (int) $match[1] * 1024 : null;
		return $rss === null || $peak === null ? [null, null] : [$rss, max($rss, $peak)];
	}

	public function sample(int $now, bool $force = false) : void{
		$interval = $now - $this->previous;
		if($interval < 0 || (!$force && $interval < self::INTERVAL_NS) || ($interval === 0 && count($this->samples) > 0)){ return; }
		// Coarse CPU counters can turn short stop intervals into false spikes.
		$usage = ($interval === 0 || $interval >= self::INTERVAL_NS) && function_exists("getrusage") ? getrusage() : false;
		$user = $usage === false ? null : $usage["ru_utime.tv_sec"] * 1000000000 + $usage["ru_utime.tv_usec"] * 1000;
		$system = $usage === false ? null : $usage["ru_stime.tv_sec"] * 1000000000 + $usage["ru_stime.tv_usec"] * 1000;
		$user = $user === null || $user >= PHP_INT_MAX ? null : (int) $user;
		$system = $system === null || $system >= PHP_INT_MAX ? null : (int) $system;
		if($user === null || $system === null){ $user = $system = null; }
		[$rss, $rssPeak] = $this->rss();
		$row = [
			$now - $this->started, $interval,
			$user === null || $this->user === null ? null : max(0, $user - $this->user),
			$system === null || $this->system === null ? null : max(0, $system - $this->system),
			memory_get_usage(), memory_get_usage(true), $rss, $rssPeak
		];
		if(count($this->samples) === self::MAX_SAMPLES){ ++$this->dropped; }
		$this->samples[$this->cursor] = $row;
		$this->cursor = ($this->cursor + 1) % self::MAX_SAMPLES;
		$this->previous = $now;
		$this->user = $user;
		$this->system = $system;
	}

	/** @return ResourceCapture */
	public function capture() : array{
		return [
			"samples" => $this->dropped === 0 ? $this->samples : array_merge(array_slice($this->samples, $this->cursor), array_slice($this->samples, 0, $this->cursor)),
			"samples_dropped" => $this->dropped
		];
	}

	/** @return ResourceStats */
	public function stats() : array{
		$last = $this->samples[($this->cursor + self::MAX_SAMPLES - 1) % self::MAX_SAMPLES];
		$cpu = $peak = null;
		$total = 0.0;
		$elapsed = $memoryPeak = 0;
		foreach($this->samples as $row){
			$memoryPeak = max($memoryPeak, $row[4]);
			if($row[1] > 0 && $row[2] !== null && $row[3] !== null){
				$usage = ((float) $row[2] + $row[3]) / $row[1] * 100.0;
				$peak = max($peak ?? 0.0, $usage);
				$total += (float) $row[2] + $row[3];
				$elapsed += $row[1];
			}
		}
		if($last[1] > 0 && $last[2] !== null && $last[3] !== null){ $cpu = ((float) $last[2] + $last[3]) / $last[1] * 100.0; }
		return [
			"cpu_percent" => $cpu, "cpu_avg_percent" => $elapsed === 0 ? null : $total / $elapsed * 100.0, "cpu_peak_percent" => $peak,
			"memory_bytes" => $last[4], "memory_allocated_bytes" => $last[5], "memory_peak_bytes" => $memoryPeak,
			"memory_growth_bytes" => $last[4] - $this->initialMemory,
			"rss_bytes" => $last[6], "rss_peak_bytes" => $last[7],
			"rss_growth_bytes" => $last[6] === null || $this->initialRss === null ? null : $last[6] - $this->initialRss
		];
	}
}
