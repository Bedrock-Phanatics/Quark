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

use DaveRandom\CallbackValidator\CallbackType;
use DaveRandom\CallbackValidator\ReturnType;
use quark\utils\Utils;

/**
 * Task implementation which allows closures to be called by a scheduler.
 *
 * Example usage:
 *
 * ```
 * TaskScheduler->scheduleTask(new ClosureTask(function() : void{
 *     echo "HI\n";
 * });
 * ```
 */
class ClosureTask extends Task{
	/**
	 * @param \Closure $closure Must accept zero parameters
	 * @phpstan-param \Closure() : void $closure
	 */
	public function __construct(
		private \Closure $closure
	){
		Utils::validateCallableSignature(new CallbackType(new ReturnType()), $closure);
	}

	public function getName() : string{
		return Utils::getNiceClosureName($this->closure);
	}

	public function onRun() : void{
		($this->closure)();
	}
}
