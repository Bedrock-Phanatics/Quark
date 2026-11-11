<?php

/*
 * This file is part of Quark.
 * Copyright (C) Quark Team <https://github.com/Bedrock-Phanatics/Quark>
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace quark\camera;

use Closure;
use InvalidArgumentException;
use function count;
use function strlen;

final class CameraTimeline{

	/** @var list<CameraInstruction|float|string|Closure(CameraPlayback) : void> */
	private array $steps = [];
	private int $iterations = 1;
	private bool $reset = true;

	public function instruction(CameraInstruction $instruction) : self{
		return $this->append($instruction);
	}

	public function wait(float $seconds) : self{
		CameraValidation::duration($seconds);
		return $this->append($seconds);
	}

	public function awaitSignal(string $signal) : self{
		self::validateSignal($signal);
		return $this->append($signal);
	}

	/** @param Closure(CameraPlayback) : void $callback */
	public function callback(Closure $callback) : self{
		return $this->append($callback);
	}

	public function loop(int $iterations) : self{
		if($iterations < 1 || $iterations > 10000){
			throw new InvalidArgumentException("Camera timeline iterations must be between 1 and 10000");
		}
		$result = clone $this;
		$result->iterations = $iterations;
		return $result;
	}

	public function resetAfter(bool $reset = true) : self{
		$result = clone $this;
		$result->reset = $reset;
		return $result;
	}

	/** @return list<CameraInstruction|float|string|Closure(CameraPlayback) : void> */
	public function getSteps() : array{
		return $this->steps;
	}

	public function getIterations() : int{
		return $this->iterations;
	}

	public function shouldResetAfter() : bool{
		return $this->reset;
	}

	/** @internal */
	public static function validateSignal(string $signal) : void{
		if($signal === "" || strlen($signal) > 128){
			throw new InvalidArgumentException("Camera timeline signals must contain 1 to 128 bytes");
		}
	}

	/** @param CameraInstruction|float|string|Closure(CameraPlayback) : void $step */
	private function append(CameraInstruction|float|string|Closure $step) : self{
		if(count($this->steps) >= 4096){
			throw new InvalidArgumentException("Camera timelines cannot contain more than 4096 steps");
		}
		$result = clone $this;
		$result->steps[] = $step;
		return $result;
	}
}
