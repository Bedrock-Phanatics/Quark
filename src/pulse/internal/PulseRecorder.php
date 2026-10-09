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

use quark\promise\Promise;
use quark\promise\PromiseResolver;
use quark\pulse\Pulse;
use quark\pulse\PulseReport;
use quark\pulse\PulseSession;
use quark\scheduler\AsyncPool;
use quark\scheduler\PulseControlTask;
use function hrtime;

/**
 * @internal Main-thread session coordination; workers keep their own counters.
 * @phpstan-import-type Capture from PulseContext
 */
final class PulseRecorder{
	private ?PulseSession $session = null;
	private int $generation = 0;
	private int $controls = 0;
	private bool $collecting = false;
	private bool $running = false;
	private int $deadline = 0;
	private int $duration = 0;
	private int $threshold = 0;
	private int $maxSpikes = 32;

	public function __construct(private readonly AsyncPool $pool){
		$pool->addWorkerStartHook(function(int $worker) : void{
			if($this->isRecording()){
				$this->startWorker($worker);
			}
		});
	}

	public function isRecording() : bool{ return $this->session?->isRecording() ?? false; }
	public function getSession() : ?PulseSession{ return $this->session; }

	public function start(int $durationNs = 0, int $spikeThresholdNs = 0, int $maxSpikes = 32) : void{
		$this->requireIdleControls();
		if($this->isRecording()){
			throw new \LogicException("Pulse is already recording");
		}
		if($this->pool->getSize() > 127){
			throw new \LengthException("Pulse supports up to 127 async workers");
		}
		$this->session = Pulse::start("main", $durationNs, $spikeThresholdNs, $maxSpikes);
		$this->duration = $durationNs;
		$this->threshold = $spikeThresholdNs;
		$this->maxSpikes = $maxSpikes;
		$this->deadline = $durationNs === 0 ? 0 : (int) hrtime(true) + $durationNs;
		++$this->generation;
		$this->running = true;
		foreach($this->pool->getRunningWorkers() as $worker){
			$this->startWorker($worker);
		}
	}

	private function startWorker(int $worker) : void{
		++$this->controls;
		$this->pool->submitTaskToWorker(new PulseControlTask(
			PulseControlTask::START, $this->generation,
			function(?array $capture) : void{ --$this->controls; },
			"worker#$worker", $this->deadline, $this->threshold, $this->maxSpikes
		), $worker);
	}

	public function stop() : void{
		$this->session?->stop();
		if(!$this->running){ return; }
		$this->running = false;
		foreach($this->pool->getRunningWorkers() as $worker){
			++$this->controls;
			$this->pool->submitTaskToWorker(new PulseControlTask(
				PulseControlTask::STOP, $this->generation,
				function(?array $capture) : void{ --$this->controls; }
			), $worker);
		}
	}

	public function checkDuration() : void{
		if($this->running && !$this->isRecording()){
			$this->stop();
		}
	}

	public function reset() : void{
		$this->requireIdleControls();
		$recording = $this->isRecording();
		$this->stop();
		if($recording){
			// Worker queues apply the stop before the new start.
			$this->session = Pulse::start("main", $this->duration, $this->threshold, $this->maxSpikes);
			++$this->generation;
			$this->deadline = $this->duration === 0 ? 0 : (int) hrtime(true) + $this->duration;
			$this->running = true;
			foreach($this->pool->getRunningWorkers() as $worker){ $this->startWorker($worker); }
		}else{
			Pulse::reset();
			$this->session = null;
			++$this->generation;
		}
	}

	private function requireIdleControls() : void{
		if($this->controls > 0 || $this->collecting){
			throw new \LogicException("Pulse is waiting for workers; try again after they finish");
		}
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @return Promise<PulseReport>
	 */
	public function collect(array $metadata = []) : Promise{
		if($this->collecting){ throw new \LogicException("Pulse is already collecting a report"); }
		if($this->session === null){ throw new \LogicException("No Pulse session to report"); }
		$this->collecting = true;
		$main = $this->session->getCapture();
		$promises = [];
		foreach($this->pool->getRunningWorkers() as $worker){
			/** @var PromiseResolver<Capture|null> $workerResult */
			$workerResult = new PromiseResolver();
			$this->pool->submitTaskToWorker(new PulseControlTask(
				PulseControlTask::COLLECT, $this->generation,
				fn(?array $capture) => $workerResult->resolve($capture)
			), $worker);
			$promises[] = $workerResult->getPromise();
		}
		/** @var PromiseResolver<PulseReport> $result */
		$result = new PromiseResolver();
		Promise::all($promises)->onCompletion(
			function(array $captures) use ($main, $metadata, $result) : void{
				$this->collecting = false;
				$threads = [$main];
				foreach($captures as $capture){ if($capture !== null){ $threads[] = $capture; } }
				try{
					$report = PulseReport::create($threads, $metadata);
				}catch(\InvalidArgumentException|\LengthException){
					$result->reject();
					return;
				}
				$result->resolve($report);
			},
			function() use ($result) : void{ $this->collecting = false; $result->reject(); }
		);
		return $result->getPromise();
	}
}
