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

use pmmp\thread\Thread as NativeThread;
use quark\MemoryDump;
use Symfony\Component\Filesystem\Path;
use function assert;

/**
 * Task used to dump memory from AsyncWorkers
 */
class DumpWorkerMemoryTask extends AsyncTask{
	public function __construct(
		private string $outputFolder,
		private int $maxNesting,
		private int $maxStringSize
	){}

	public function onRun() : void{
		$worker = NativeThread::getCurrentThread();
		assert($worker instanceof AsyncWorker);
		MemoryDump::dumpMemory(
			$worker,
			Path::join($this->outputFolder, "AsyncWorker#" . $worker->getAsyncWorkerId()),
			$this->maxNesting,
			$this->maxStringSize,
			new \PrefixedLogger($worker->getLogger(), "Memory Dump")
		);
	}
}
