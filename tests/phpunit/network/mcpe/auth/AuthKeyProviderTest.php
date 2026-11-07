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

namespace quark\network\mcpe\auth;

use PHPUnit\Framework\TestCase;
use quark\scheduler\AsyncPool;
use quark\scheduler\AsyncTask;

final class AuthKeyProviderTest extends TestCase{
	/** @var list<FetchAuthKeysTask> */
	private array $tasks = [];

	private function provider() : AuthKeyProvider{
		$pool = $this->createMock(AsyncPool::class);
		$pool->method("submitTask")->willReturnCallback(function(AsyncTask $task) : int{
			self::assertInstanceOf(FetchAuthKeysTask::class, $task);
			$this->tasks[] = $task;
			return 0;
		});
		return new AuthKeyProvider(self::createStub(\Logger::class), $pool);
	}

	/**
	 * @phpstan-param array<string, mixed>|null $keys
	 * @phpstan-param list<string>|null         $errors
	 */
	private function complete(int $task, ?array $keys, ?array $errors) : void{
		/** @var \Closure(?array<string, mixed>, string, ?list<string>) : void $callback */
		$callback = (new \ReflectionMethod(AsyncTask::class, "fetchLocal"))->invoke($this->tasks[$task], "completion");
		$callback($keys, "issuer", $errors);
	}

	public function testConcurrentLookupsShareOneFetchAndRetryAfterFailure() : void{
		$provider = $this->provider();
		$first = $provider->getKey("a");
		$second = $provider->getKey("b");
		self::assertCount(1, $this->tasks, "lookups during a refresh share it");

		$this->complete(0, null, ["unreachable"]);
		self::assertSame("Failed to fetch authentication keys", $first->reason()?->getMessage());
		self::assertSame("Failed to fetch authentication keys", $second->reason()?->getMessage());

		$provider->getKey("a")->observe(null, static function() : void{});
		self::assertCount(2, $this->tasks, "a failed refresh is retried");
	}

	public function testNoUsableKeysRejects() : void{
		$provider = $this->provider();
		$promise = $provider->getKey("a");
		$this->complete(0, [], null);
		self::assertSame("No valid authentication keys were returned", $promise->reason()?->getMessage());
	}
}
