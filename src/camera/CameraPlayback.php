<?php

/*
 * This file is part of Quark.
 * Copyright (C) Quark Team <https://github.com/Bedrock-Phanatics/Quark>
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace quark\camera;

use Closure;
use LogicException;
use quark\plugin\Plugin;
use Throwable;
use WeakReference;
use function ceil;
use function count;
use function is_float;
use function is_string;

final class CameraPlayback{
	/** @var WeakReference<CameraController> */
	private WeakReference $camera;
	/** @var WeakReference<Plugin> */
	private WeakReference $owner;
	/** @var list<CameraInstruction|float|string|Closure(CameraPlayback) : void> */
	private array $steps;
	private int $iterations;
	private bool $resetAfter;
	private bool $started = false;
	private bool $running = false;
	private bool $advancing = false;
	private int $index = 0;
	private ?string $signal = null;
	private ?CameraTimelineTask $task = null;

	/** @internal */
	public function __construct(CameraController $camera, CameraTimeline $timeline, Plugin $owner){
		if(!$owner->isEnabled()){
			throw new LogicException("Cannot start a camera timeline for a disabled plugin");
		}
		if(!$camera->isReady()){
			throw new LogicException("The player's camera is not ready");
		}
		$this->camera = WeakReference::create($camera);
		$this->owner = WeakReference::create($owner);
		$this->steps = $timeline->getSteps();
		$this->iterations = $timeline->getIterations();
		$this->resetAfter = $timeline->shouldResetAfter();
	}

	/** @internal */
	public function start() : void{
		if($this->started){
			throw new LogicException("A camera playback can only be started once");
		}
		$this->started = true;
		$this->running = true;
		$this->advance();
	}

	public function isRunning() : bool{
		return $this->running;
	}

	public function cancel(bool $reset = true) : void{
		if(!$this->running){
			return;
		}
		$this->finish($reset);
	}

	public function signal(string $signal) : bool{
		CameraTimeline::validateSignal($signal);
		if(!$this->running || $this->signal !== $signal){
			return false;
		}
		$this->signal = null;
		$this->advance();
		return true;
	}

	public function send(CameraInstruction $instruction) : void{
		$camera = $this->camera->get();
		if(!$this->running || $camera === null){
			throw new LogicException("This camera playback is no longer active");
		}
		$camera->sendForPlayback($instruction, $this);
	}

	/** @internal */
	public function resume(CameraTimelineTask $task) : void{
		if($this->task !== $task || !$this->running){
			return;
		}
		$this->task = null;
		$this->advance();
	}

	/** @internal */
	public function onTaskCancelled(CameraTimelineTask $task) : void{
		if($this->task === $task){
			$this->task = null;
			$camera = $this->camera->get();
			$this->cancel(false);
			$camera?->resetForContext();
		}
	}

	private function advance() : void{
		if($this->advancing || !$this->running){
			return;
		}
		$this->advancing = true;
		try{
			for($budget = 64; $this->running && $budget > 0; --$budget){
				$camera = $this->camera->get();
				$owner = $this->owner->get();
				if($camera === null || !$camera->isReady() || $owner === null || !$owner->isEnabled()){
					$this->cancel(false);
					return;
				}
				if($this->index >= count($this->steps)){
					if(--$this->iterations <= 0 || $this->steps === []){
						$this->finish($this->resetAfter);
						return;
					}
					$this->index = 0;
				}
				$step = $this->steps[$this->index++];
				if($step instanceof CameraInstruction){
					$this->send($step);
				}elseif(is_float($step)){
					if($step > 0){
						$this->schedule((int) ceil($step * 20));
						return;
					}
				}elseif(is_string($step)){
					$this->signal = $step;
					return;
				}else{
					$step($this);
				}
			}
			if($this->running){
				$this->schedule(1);
			}
		}catch(Throwable $e){
			try{
				$this->cancel();
			}finally{
				throw $e;
			}
		}finally{
			$this->advancing = false;
		}
	}

	private function schedule(int $ticks) : void{
		$owner = $this->owner->get();
		if($owner === null || !$owner->isEnabled()){
			$this->cancel(false);
			return;
		}
		$this->task = new CameraTimelineTask($this);
		$owner->getScheduler()->scheduleDelayedTask($this->task, $ticks);
	}

	private function finish(bool $reset) : void{
		$this->running = false;
		$this->steps = [];
		$this->signal = null;
		$task = $this->task;
		$this->task = null;
		try{
			$handler = $task?->getHandler();
			if($handler !== null){
				$owner = $this->owner->get();
				if($owner !== null){
					$owner->getScheduler()->cancelTask($handler);
				}else{
					$handler->cancel();
				}
			}
		}finally{
			$this->camera->get()?->finishPlayback($this, $reset);
		}
	}
}
