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

use pmmp\thread\ThreadSafeArray;
use quark\promise\PromiseResolver;

class ThreadSafeResultAsyncTask extends AsyncTask{
	private const TLS_KEY_PROMISE = "promise";

	/**
	 * @phpstan-param PromiseResolver<ThreadSafeArray<array-key, mixed>> $promise
	 */
	public function __construct(
		PromiseResolver $promise
	){
		$this->storeLocal(self::TLS_KEY_PROMISE, $promise);
	}

	public function onRun() : void{
		//this only works in pthreads 5.1+ and pmmpthread
		//in prior versions the ThreadSafe would be destroyed before onCompletion is called
		$result = new ThreadSafeArray();
		$result[] = "foo";
		$this->setResult($result);
	}

	public function onCompletion() : void{
		/** @var PromiseResolver<ThreadSafeArray<array-key, mixed>> $promise */
		$promise = $this->fetchLocal(self::TLS_KEY_PROMISE);
		/** @var ThreadSafeArray<array-key, mixed> $result */
		$result = $this->getResult();
		$promise->resolve($result);
	}
}
