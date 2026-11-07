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

use pocketmine\network\mcpe\protocol\types\login\openid\api\AuthServiceKey;
use quark\network\mcpe\JwtException;
use quark\network\mcpe\JwtUtils;
use quark\scheduler\AsyncPool;
use Vow\Deferred;
use Vow\Promise;
use function array_keys;
use function count;
use function implode;
use function time;

class AuthKeyProvider{
	private const ALLOWED_REFRESH_INTERVAL = 30 * 60; // 30 minutes

	private ?AuthKeyring $keyring = null;

	/**
	 * Shared by every login waiting on the same refresh.
	 * @phpstan-var Promise<AuthKeyring>|null
	 */
	private ?Promise $fetch = null;

	private int $lastFetch = 0;

	public function __construct(
		private readonly \Logger $logger,
		private readonly AsyncPool $asyncPool,
		private readonly int $keyRefreshIntervalSeconds = self::ALLOWED_REFRESH_INTERVAL
	){}

	/**
	 * Fetches the key for the given key ID.
	 * The promise will be resolved with an array of [issuer, pemPublicKey], or rejected if the key is unknown.
	 *
	 * @phpstan-return Promise<array{string, string}>
	 */
	public function getKey(string $keyId) : Promise{
		if(
			$this->keyring === null || //we haven't fetched keys yet
			($this->keyring->getKey($keyId) === null && $this->lastFetch < time() - $this->keyRefreshIntervalSeconds) //we don't recognise this one & keys might be outdated
		){
			//only refresh keys when we see one we don't recognise
			return $this->fetchKeys()->then(fn(AuthKeyring $keyring) => $this->findKey($keyring, $keyId));
		}
		try{
			return Promise::resolved($this->findKey($this->keyring, $keyId));
		}catch(\RuntimeException $e){
			return Promise::rejected($e);
		}
	}

	/**
	 * @phpstan-return array{string, string}
	 * @throws \RuntimeException
	 */
	private function findKey(AuthKeyring $keyring, string $keyId) : array{
		$key = $keyring->getKey($keyId);
		if($key === null){
			$this->logger->debug("Key $keyId not recognised!");
			throw new \RuntimeException("Unrecognized authentication key ID: $keyId");
		}
		$this->logger->debug("Key $keyId found in keychain");
		return [$keyring->getIssuer(), $key];
	}

	/**
	 * @phpstan-param array<string, AuthServiceKey>|null $keys
	 * @phpstan-param string[]|null                      $errors
	 * @throws \RuntimeException
	 */
	private function onKeysFetched(?array $keys, string $issuer, ?array $errors) : AuthKeyring{
		if($errors !== null){
			$this->logger->error("The following errors occurred while fetching new keys:\n\t- " . implode("\n\t-", $errors));
			//we might've still succeeded in fetching keys even if there were errors, so don't return
		}
		if($keys === null){
			$this->logger->critical("Failed to fetch authentication keys from Mojang's API. Xbox players may not be able to authenticate!");
			throw new \RuntimeException("Failed to fetch authentication keys");
		}

		$pemKeys = [];
		foreach($keys as $keyModel){
			if($keyModel->use !== "sig" || $keyModel->kty !== "RSA"){
				$this->logger->error("Key ID $keyModel->kid doesn't have the expected properties: expected use=sig, kty=RSA, got use=$keyModel->use, kty=$keyModel->kty");
				continue;
			}
			$derKey = JwtUtils::rsaPublicKeyModExpToDer($keyModel->n, $keyModel->e);

			//make sure the key is valid
			try{
				JwtUtils::parseDerPublicKey($derKey);
			}catch(JwtException $e){
				$this->logger->error("Failed to parse RSA public key for key ID $keyModel->kid: " . $e->getMessage());
				$this->logger->logException($e);
				continue;
			}

			//retain PEM keys instead of OpenSSLAsymmetricKey since these are easier and cheaper to copy between threads
			$pemKeys[$keyModel->kid] = $derKey;
		}

		if(count($pemKeys) === 0){
			$this->logger->critical("No valid authentication keys returned by Mojang's API. Xbox players may not be able to authenticate!");
			throw new \RuntimeException("No valid authentication keys were returned");
		}
		$this->logger->info("Successfully fetched " . count($pemKeys) . " new authentication keys from issuer $issuer, key IDs: " . implode(", ", array_keys($pemKeys)));
		$this->keyring = new AuthKeyring($issuer, $pemKeys);
		$this->lastFetch = time();
		return $this->keyring;
	}

	/**
	 * @phpstan-return Promise<AuthKeyring>
	 */
	private function fetchKeys() : Promise{
		if($this->fetch !== null){
			$this->logger->debug("Key refresh was requested, but it's already in progress");
			return $this->fetch;
		}

		$this->logger->notice("Fetching new authentication keys");

		/** @phpstan-var Deferred<AuthKeyring> $deferred */
		$deferred = new Deferred();
		//TODO: extract this so it can be polyfilled for unit testing
		$this->asyncPool->submitTask(new FetchAuthKeysTask(function(?array $keys, string $issuer, ?array $errors) use ($deferred) : void{
			try{
				$keyring = $this->onKeysFetched($keys, $issuer, $errors);
			}catch(\RuntimeException $e){
				$deferred->reject($e);
				return;
			}
			$deferred->resolve($keyring);
		}));
		//a failed refresh is retried by the next login that needs it
		return $this->fetch = $deferred->promise->finally(function() : void{ $this->fetch = null; });
	}
}
