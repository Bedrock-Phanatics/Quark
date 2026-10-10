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
use function array_values;
use function count;
use function hrtime;
use function ksort;

/**
 * @internal Main-thread session coordination; workers keep their own counters.
 * @phpstan-import-type Capture from PulseContext
 */
final class PulseRecorder{
	private ?PulseSession $session = null;
	private int $generation = 0;
	private int $controls = 0;
	/** @var PromiseResolver<list<PulseCapture>>|null */
	private ?PromiseResolver $collection = null;
	private int $collectionDeadline = 0;
	private int $collectionRows = 0;
	private int $collectionBytes = 0;
	private int $pendingCollections = 0;
	/** @var array<int, PulseCapture> */
	private array $captures = [];
	private bool $running = false;
	/** @var array<int, true> */
	private array $stoppedWorkers = [];
	private int $deadline = 0;
	private int $duration = 0;
	private int $threshold = 0;
	private int $maxSpikes = 32;

	public function __construct(private readonly AsyncPool $pool){
		$pool->addWorkerStartHook(function(int $worker) : void{
			if($this->isRecording()){
				$this->control(PulseControlTask::START, $worker);
			}
		});
	}

	public function isRecording() : bool{ return $this->session?->isRecording() ?? false; }
	public function getSession() : ?PulseSession{ return $this->session; }
	public function getPendingOperations() : int{ return $this->controls + $this->pendingCollections; }

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
		$this->stoppedWorkers = [];
		foreach($this->pool->getRunningWorkers() as $worker){
			$this->control(PulseControlTask::START, $worker);
		}
	}

	private function control(int $operation, int $worker) : void{
		++$this->controls;
		try{
			$this->pool->submitTaskToWorker(new PulseControlTask(
				$operation, $this->generation,
				function(?PulseCapture $capture) : void{ --$this->controls; },
				"worker#$worker", $this->deadline, $this->threshold, $this->maxSpikes
			), $worker);
		}catch(\Throwable $e){
			--$this->controls;
			if($operation === PulseControlTask::START){ $this->session?->stop(); }
			throw $e;
		}
	}

	public function stop() : void{
		$this->session?->stop();
		if(!$this->running){ return; }
		foreach($this->pool->getRunningWorkers() as $worker){
			if(!isset($this->stoppedWorkers[$worker])){
				$this->control(PulseControlTask::STOP, $worker);
				$this->stoppedWorkers[$worker] = true;
			}
		}
		$this->running = false;
		$this->stoppedWorkers = [];
	}

	public function checkDuration() : void{
		if($this->running && !$this->isRecording()){
			$this->stop();
		}
		if($this->collection !== null && hrtime(true) >= $this->collectionDeadline){
			$this->finishCollection(false);
		}
	}

	public function reset() : void{
		$this->requireIdleControls();
		$recording = $this->isRecording();
		$this->stop();
		Pulse::reset();
		if($recording){
			// Worker queues apply the stop before the new start.
			$this->session = Pulse::start("main", $this->duration, $this->threshold, $this->maxSpikes);
			++$this->generation;
			$this->deadline = $this->duration === 0 ? 0 : (int) hrtime(true) + $this->duration;
			$this->running = true;
			foreach($this->pool->getRunningWorkers() as $worker){ $this->control(PulseControlTask::START, $worker); }
		}else{
			$this->session = null;
			++$this->generation;
			foreach($this->pool->getRunningWorkers() as $worker){ $this->control(PulseControlTask::RESET, $worker); }
		}
	}

	private function requireIdleControls() : void{
		if($this->getPendingOperations() > 0 || $this->collection !== null){
			throw new \LogicException("Pulse is waiting for workers; try again after they finish");
		}
	}

	/**
	 * @param array<string, mixed> $metadata
	 * @return Promise<PulseReport>
	 */
	public function collect(array $metadata = []) : Promise{
		/** @var PromiseResolver<PulseReport> $result */
		$result = new PromiseResolver();
		$this->collectCaptures()->onCompletion(
			static function(array $captures) use ($metadata, $result) : void{
				try{ $report = PulseReport::create($captures, $metadata); }catch(\InvalidArgumentException|\LengthException){ $result->reject(); return; }
				$result->resolve($report);
			},
			fn() => $result->reject()
		);
		return $result->getPromise();
	}

	/** @return Promise<list<Capture>> */
	public function collectCaptures() : Promise{
		/** @var PromiseResolver<list<Capture>> $result */
		$result = new PromiseResolver();
		$this->collectTransfers()->onCompletion(
			static function(array $captures) use ($result) : void{
				$data = [];
				foreach($captures as $capture){ $data[] = $capture->decode(); }
				$result->resolve($data);
			},
			fn() => $result->reject()
		);
		return $result->getPromise();
	}

	/** @return Promise<list<PulseCapture>> */
	public function collectTransfers() : Promise{
		if($this->collection !== null){ throw new \LogicException("Pulse is already collecting a report"); }
		if($this->pendingCollections > 0){ throw new \LogicException("Pulse is still waiting for the previous report's workers"); }
		$this->checkDuration();
		if($this->session === null){ throw new \LogicException("No Pulse session to report"); }
		$main = new PulseCapture($this->session->getCapture());
		$workers = $this->pool->getRunningWorkers();
		/** @var PromiseResolver<list<PulseCapture>> $result */
		$result = new PromiseResolver();
		$this->collection = $result;
		$this->captures = [$main];
		$this->collectionRows = $main->getRowCount();
		$this->collectionBytes = $main->getByteSize();
		$this->collectionDeadline = (int) hrtime(true) + 30000000000;
		$this->pendingCollections = count($workers);
		$submitted = 0;
		try{
			foreach($workers as $worker){
				$this->pool->submitTaskToWorker(new PulseControlTask(
					PulseControlTask::COLLECT, $this->generation,
					function(?PulseCapture $capture) use ($worker) : void{
						--$this->pendingCollections;
						if($this->collection === null){ return; }
						if(hrtime(true) >= $this->collectionDeadline){ $this->finishCollection(false); return; }
						if($capture !== null){
							$this->collectionRows += $capture->getRowCount();
							$this->collectionBytes += $capture->getByteSize();
							if($this->collectionRows > PulseReport::MAX_ROWS || $this->collectionBytes > PulseCapture::MAX_TRANSFER_BYTES){ $this->finishCollection(false); return; }
							$this->captures[$worker + 1] = $capture;
						}
						if($this->pendingCollections === 0){ $this->finishCollection(true); }
					}
				), $worker);
				++$submitted;
			}
		}catch(\Throwable $e){
			$this->pendingCollections -= count($workers) - $submitted;
			$this->finishCollection(false);
			throw $e;
		}
		if($this->pendingCollections === 0){ $this->finishCollection(true); }
		return $result->getPromise();
	}

	private function finishCollection(bool $success) : void{
		$result = $this->collection;
		if($result === null){ return; }
		$captures = null;
		if($success){
			ksort($this->captures);
			$captures = array_values($this->captures);
		}
		// Release report payloads before invoking callbacks or accepting another collection.
		$this->collection = null;
		$this->captures = [];
		$this->collectionDeadline = $this->collectionRows = $this->collectionBytes = 0;
		if($captures !== null){ $result->resolve($captures); }else{ $result->reject(); }
	}
}
