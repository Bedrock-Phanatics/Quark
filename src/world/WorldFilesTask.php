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

namespace quark\world;

use quark\scheduler\AsyncTask;
use quark\utils\Filesystem;
use function is_string;

/**
 * Copies or deletes a world folder off the main thread.
 * @internal
 */
final class WorldFilesTask extends AsyncTask{
	private const TLS_KEY_ON_DONE = "onDone";

	/**
	 * @phpstan-param \Closure(?string $error) : void $onDone
	 */
	public function __construct(
		private string $source,
		private ?string $copyTarget,
		\Closure $onDone
	){
		$this->storeLocal(self::TLS_KEY_ON_DONE, $onDone);
	}

	public function onRun() : void{
		try{
			if($this->copyTarget === null){
				Filesystem::recursiveUnlink($this->source);
			}else{
				Filesystem::recursiveCopy($this->source, $this->copyTarget);
			}
			$this->setResult(null);
		}catch(\Throwable $e){
			if($this->copyTarget !== null){
				try{
					Filesystem::recursiveUnlink($this->copyTarget);
				}catch(\Throwable){
					//the copy error is the one worth reporting
				}
			}
			$this->setResult($e->getMessage());
		}
	}

	public function onCompletion() : void{
		/** @phpstan-var \Closure(?string $error) : void $onDone */
		$onDone = $this->fetchLocal(self::TLS_KEY_ON_DONE);
		$result = $this->getResult();
		$onDone(is_string($result) ? $result : null);
	}
}
