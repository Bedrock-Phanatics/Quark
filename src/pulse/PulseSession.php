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
use function array_slice;
use function arsort;
use function hrtime;
use function max;
use function usort;
use const SORT_NUMERIC;

/**
 * @phpstan-import-type Capture from PulseContext
 * @phpstan-import-type Stats from PulseContext
 * @phpstan-type TopZone array{name: string, calls: int, self_ns: int, max_ns: int}
 * @phpstan-type SpikeSummary array{tick_id: int, offset_ns: int, duration_ns: int, zone: string|null, self_ns: int}
 */
final class PulseSession{
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
		foreach(array_slice($spikes, 0, $limit) as $spike){
			$name = null;
			$self = 0;
			foreach($spike["nodes"] as $node){
				if($node[3] > $self){
					$self = $node[3];
					$name = $capture["zones"][$capture["nodes"][$node[0] - 1][1]];
				}
			}
			$result[] = ["tick_id" => $spike["tick"][0], "offset_ns" => $spike["tick"][1], "duration_ns" => $spike["tick"][2], "zone" => $name, "self_ns" => $self];
		}
		return $result;
	}

	/** @param array<string, mixed> $metadata */
	public function getReport(array $metadata = []) : PulseReport{
		return PulseReport::create([$this->getCapture()], $metadata);
	}
}
