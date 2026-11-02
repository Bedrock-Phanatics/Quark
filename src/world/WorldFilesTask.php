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
use function basename;
use function bin2hex;
use function dirname;
use function file_exists;
use function is_dir;
use function is_string;
use function mkdir;
use function random_bytes;
use function rename;
use function rtrim;

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
				$trash = self::hiddenSibling($this->source, "deleting");
				if(!@rename(rtrim($this->source, "/\\"), $trash)){
					throw new \RuntimeException("Failed to move " . $this->source . " aside for deletion");
				}
				Filesystem::recursiveUnlink($trash);
			}else{
				$this->copy($this->copyTarget);
			}
			$this->setResult(null);
		}catch(\Throwable $e){
			$this->setResult($e->getMessage());
		}
	}

	private function copy(string $target) : void{
		$target = rtrim($target, "/\\");
		$parent = dirname($target);
		if(!is_dir($parent) && !@mkdir($parent, 0777, true) && !is_dir($parent)){
			throw new \RuntimeException("Failed to create directory $parent");
		}
		$staging = self::hiddenSibling($target, "copying");
		if(!@mkdir($staging)){
			throw new \RuntimeException("Failed to create directory $staging");
		}
		try{
			Filesystem::recursiveCopy($this->source, $staging);
			if(file_exists($target) || !@rename($staging, $target)){
				throw new \RuntimeException("Failed to move the copy to $target, it may already exist");
			}
		}catch(\Throwable $e){
			try{
				Filesystem::recursiveUnlink($staging);
			}catch(\Throwable){
				//the copy error is the one worth reporting
			}
			throw $e;
		}
	}

	/**
	 * Hidden names are never valid world names, so a half finished copy or delete is never seen as a world.
	 */
	private static function hiddenSibling(string $path, string $purpose) : string{
		$path = rtrim($path, "/\\");
		return dirname($path) . "/." . basename($path) . ".$purpose-" . bin2hex(random_bytes(4));
	}

	public function onCompletion() : void{
		/** @phpstan-var \Closure(?string $error) : void $onDone */
		$onDone = $this->fetchLocal(self::TLS_KEY_ON_DONE);
		$result = $this->getResult();
		$onDone(is_string($result) ? $result : null);
	}
}
