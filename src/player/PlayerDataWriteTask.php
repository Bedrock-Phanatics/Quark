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

namespace quark\player;

use quark\scheduler\AsyncTask;
use Vow\Deferred;
use function is_string;

/**
 * Compresses and writes one player's data off the main thread.
 * @internal
 */
final class PlayerDataWriteTask extends AsyncTask{
	private const TLS_KEY_DEFERRED = "deferred";

	/**
	 * @phpstan-param Deferred<null> $deferred
	 */
	public function __construct(
		private string $path,
		private string $nbt,
		Deferred $deferred
	){
		$this->storeLocal(self::TLS_KEY_DEFERRED, $deferred);
	}

	public function onRun() : void{
		try{
			DatFilePlayerDataProvider::writeFile($this->path, $this->nbt);
			$this->setResult(null);
		}catch(\Throwable $e){
			$this->setResult($e->getMessage());
		}
	}

	public function onCompletion() : void{
		/** @phpstan-var Deferred<null> $deferred */
		$deferred = $this->fetchLocal(self::TLS_KEY_DEFERRED);
		$error = $this->getResult();
		if(is_string($error)){
			$deferred->reject(new PlayerDataSaveException($error));
		}else{
			$deferred->resolve(null);
		}
	}
}
