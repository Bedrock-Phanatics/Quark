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
use quark\world\format\io\WorldMigration;
use Vow\Deferred;
use function is_array;
use function is_string;

/**
 * Converts or verifies an unloaded world off the main thread. Activation happens here too, so a server shutting
 * down waits for it rather than leaving the swap half done.
 * @internal
 */
final class WorldConvertTask extends AsyncTask{
	private const TLS_KEY_DEFERRED = "deferred";

	/**
	 * @param string|null $format null verifies instead of converting
	 * @phpstan-param Deferred<array<string, int|string>> $deferred
	 */
	public function __construct(
		private string $path,
		private ?string $format,
		private string $backupDir,
		Deferred $deferred
	){
		$this->storeLocal(self::TLS_KEY_DEFERRED, $deferred);
	}

	public function onRun() : void{
		try{
			$this->setResult($this->format === null ? WorldMigration::verify($this->path) : WorldMigration::convert($this->path, $this->format, $this->backupDir));
		}catch(\Throwable $e){
			$this->setResult($e->getMessage());
		}
	}

	public function onCompletion() : void{
		/** @phpstan-var Deferred<array<string, int|string>> $deferred */
		$deferred = $this->fetchLocal(self::TLS_KEY_DEFERRED);
		$result = $this->getResult();
		if(is_array($result)){
			/** @phpstan-var array<string, int|string> $result */
			$deferred->resolve($result);
		}else{
			$deferred->reject(new \RuntimeException(is_string($result) ? $result : "World conversion failed"));
		}
	}
}
