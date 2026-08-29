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

use quark\promise\PromiseResolver;
use quark\timings\TimingsHandler;

/**
 * @phpstan-type Resolver PromiseResolver<list<string>>
 */
final class TimingsCollectionTask extends AsyncTask{
	private const TLS_KEY_RESOLVER = "resolver";

	/**
	 * @phpstan-param PromiseResolver<list<string>> $promiseResolver
	 */
	public function __construct(PromiseResolver $promiseResolver){
		$this->storeLocal(self::TLS_KEY_RESOLVER, $promiseResolver);
	}

	public function onRun() : void{
		$this->setResult(TimingsHandler::printCurrentThreadRecords());
	}

	public function onCompletion() : void{
		/**
		 * @var string[] $result
		 * @phpstan-var list<string> $result
		 */
		$result = $this->getResult();
		/**
		 * @var PromiseResolver $promiseResolver
		 * @phpstan-var PromiseResolver<list<string>> $promiseResolver
		 */
		$promiseResolver = $this->fetchLocal(self::TLS_KEY_RESOLVER);

		$promiseResolver->resolve($result);
	}
}
