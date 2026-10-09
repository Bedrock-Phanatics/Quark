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

namespace quark\scheduler;

use quark\pulse\internal\PulseContext;
use quark\pulse\Pulse;
use function hrtime;

/**
 * @phpstan-import-type Capture from PulseContext
 */
final class PulseControlTask extends AsyncTask{
	public const START = 1;
	public const STOP = 2;
	public const COLLECT = 3;
	private static int $currentGeneration = 0;

	/** @param \Closure(Capture|null) : void $onComplete */
	public function __construct(
		private int $operation,
		private int $generation,
		\Closure $onComplete,
		private string $threadName = "worker",
		private int $deadline = 0,
		private int $spikeThreshold = 0,
		private int $maxSpikes = 32
	){
		$this->storeLocal("complete", $onComplete);
	}

	public function onRun() : void{
		if($this->operation === self::START && $this->generation > self::$currentGeneration){
			Pulse::reset();
			self::$currentGeneration = $this->generation;
			$remaining = $this->deadline === 0 ? 0 : $this->deadline - (int) hrtime(true);
			if($this->deadline === 0 || $remaining > 0){
				Pulse::start($this->threadName, $remaining, $this->spikeThreshold, $this->maxSpikes);
			}
		}elseif($this->generation === self::$currentGeneration){
			if($this->operation === self::STOP){
				Pulse::stop();
			}elseif($this->operation === self::COLLECT){
				Pulse::checkDuration();
				$this->setResult(Pulse::getSession()?->getCapture());
			}
		}
	}

	public function onCompletion() : void{
		/** @var \Closure(Capture|null) : void $complete */
		$complete = $this->fetchLocal("complete");
		/** @var Capture|null $result */
		$result = $this->getResult();
		$complete($result);
	}
}
