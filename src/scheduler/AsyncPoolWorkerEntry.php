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

use function time;

/**
 * @internal
 */
final class AsyncPoolWorkerEntry{

	public int $lastUsed;
	/**
	 * @var \SplQueue|AsyncTask[]
	 * @phpstan-var \SplQueue<AsyncTask>
	 */
	public \SplQueue $tasks;

	public function __construct(
		public readonly AsyncWorker $worker,
		public readonly int $sleeperNotifierId
	){
		$this->lastUsed = time();
		$this->tasks = new \SplQueue();
	}

	public function submit(AsyncTask $task) : void{
		$this->tasks->enqueue($task);
		$this->lastUsed = time();
		$this->worker->stack($task);
	}
}
