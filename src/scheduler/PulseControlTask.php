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

use quark\pulse\internal\PulseCapture;
use quark\pulse\Pulse;
use function hrtime;

/**
 * @internal
 */
final class PulseControlTask extends AsyncTask{
	public const START = 1;
	public const STOP = 2;
	public const COLLECT = 3;
	public const RESET = 4;
	private static int $currentGeneration = 0;

	/** @param \Closure(PulseCapture|null) : void $onComplete */
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
		if(($this->operation === self::START || $this->operation === self::RESET) && $this->generation > self::$currentGeneration){
			Pulse::reset();
			self::$currentGeneration = $this->generation;
			if($this->operation === self::RESET){ return; }
			$remaining = $this->deadline === 0 ? 0 : $this->deadline - (int) hrtime(true);
			if($this->deadline === 0 || $remaining > 0){
				Pulse::start($this->threadName, $remaining, $this->spikeThreshold, $this->maxSpikes);
			}
		}elseif($this->generation === self::$currentGeneration){
			if($this->operation === self::STOP){
				Pulse::stop();
			}elseif($this->operation === self::COLLECT){
				Pulse::checkDuration();
				$capture = Pulse::getSession()?->getCapture();
				$this->setResult($capture !== null ? new PulseCapture($capture) : null);
			}
		}
	}

	public function onCompletion() : void{
		/** @var \Closure(PulseCapture|null) : void $complete */
		$complete = $this->fetchLocal("complete");
		/** @var PulseCapture|null $result */
		$result = $this->getResult();
		$complete($result);
	}
}
