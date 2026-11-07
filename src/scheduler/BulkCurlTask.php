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

use quark\utils\Internet;
use quark\utils\InternetException;
use quark\utils\InternetRequestResult;
use Vow\Deferred;
use function igbinary_serialize;
use function igbinary_unserialize;

/**
 * Executes a consecutive list of cURL operations.
 *
 * The deferred is resolved with one {@link InternetRequestResult} or {@link InternetException} per operation, in order.
 */
class BulkCurlTask extends AsyncTask{
	private const TLS_KEY_DEFERRED = "deferred";

	private string $operations;

	/**
	 * @param BulkCurlTaskOperation[] $operations
	 * @phpstan-param list<BulkCurlTaskOperation>                             $operations
	 * @phpstan-param Deferred<list<InternetRequestResult|InternetException>> $deferred
	 */
	public function __construct(array $operations, Deferred $deferred){
		$this->operations = igbinary_serialize($operations);
		$this->storeLocal(self::TLS_KEY_DEFERRED, $deferred);
	}

	public function onRun() : void{
		/**
		 * @var BulkCurlTaskOperation[] $operations
		 * @phpstan-var list<BulkCurlTaskOperation> $operations
		 */
		$operations = igbinary_unserialize($this->operations);
		$results = [];
		foreach($operations as $op){
			try{
				$results[] = Internet::simpleCurl($op->getPage(), $op->getTimeout(), $op->getExtraHeaders(), $op->getExtraOpts());
			}catch(InternetException $e){
				$results[] = $e;
			}
		}
		$this->setResult($results);
	}

	public function onCompletion() : void{
		/** @phpstan-var Deferred<list<InternetRequestResult|InternetException>> $deferred */
		$deferred = $this->fetchLocal(self::TLS_KEY_DEFERRED);
		/** @phpstan-var list<InternetRequestResult|InternetException> $results */
		$results = $this->getResult();
		$deferred->resolve($results);
	}
}
