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
use Vow\Deferred;
use function chr;

class CompressBatchTask extends AsyncTask{

	private const TLS_KEY_DEFERRED = "deferred";

	/** @phpstan-var NonThreadSafeValue<Compressor> */
	private NonThreadSafeValue $compressor;

	/**
	 * @phpstan-param Deferred<string> $deferred
	 */
	public function __construct(
		private string $data,
		Deferred $deferred,
		Compressor $compressor
	){
		$this->compressor = new NonThreadSafeValue($compressor);
		$this->storeLocal(self::TLS_KEY_DEFERRED, $deferred);
	}

	public function onRun() : void{
		$compressor = $this->compressor->deserialize();
		$this->setResult(chr($compressor->getNetworkType()->value) . $compressor->compress($this->data));
		$this->data = "";
	}

	public function onCompletion() : void{
		/** @phpstan-var Deferred<string> $deferred */
		$deferred = $this->fetchLocal(self::TLS_KEY_DEFERRED);
		/** @var string $result */
		$result = $this->getResult();
		$deferred->resolve($result);
	}
}
