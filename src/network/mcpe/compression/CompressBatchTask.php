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

namespace quark\network\mcpe\compression;

use quark\scheduler\AsyncTask;
use quark\thread\NonThreadSafeValue;
use function chr;

class CompressBatchTask extends AsyncTask{

	private const TLS_KEY_PROMISE = "promise";

	/** @phpstan-var NonThreadSafeValue<Compressor> */
	private NonThreadSafeValue $compressor;

	public function __construct(
		private string $data,
		CompressBatchPromise $promise,
		Compressor $compressor
	){
		$this->compressor = new NonThreadSafeValue($compressor);
		$this->storeLocal(self::TLS_KEY_PROMISE, $promise);
	}

	public function onRun() : void{
		$compressor = $this->compressor->deserialize();
		$this->setResult(chr($compressor->getNetworkId()) . $compressor->compress($this->data));
	}

	public function onCompletion() : void{
		/** @var CompressBatchPromise $promise */
		$promise = $this->fetchLocal(self::TLS_KEY_PROMISE);
		$promise->resolve($this->getResult());
	}
}
