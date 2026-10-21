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

use quark\lang\KnownTranslationFactory;
use quark\lang\Translatable;
use quark\scheduler\AsyncTask;
use quark\thread\NonThreadSafeValue;
use function base64_decode;

/**
 * Verifies a login chain signed by a trusted proxy. The proxy vouches for the player's Xbox Live identity when it
 * forwards an XUID.
 */
class ProcessProxyLoginTask extends AsyncTask{
	private const TLS_KEY_ON_COMPLETION = "completion";

	/** @phpstan-var NonThreadSafeValue<Translatable>|string|null */
	private NonThreadSafeValue|string|null $error = "Unknown";
	private ?string $clientPublicKeyDer = null;

	/**
	 * @phpstan-param \Closure(bool $isAuthenticated, bool $authRequired, Translatable|string|null $error, ?string $clientPublicKey) : void $onCompletion
	 */
	public function __construct(
		private string $chainJwt,
		private string $proxyKeyDer,
		private string $clientDataJwt,
		private bool $xboxLive,
		private bool $authRequired,
		\Closure $onCompletion
	){
		$this->storeLocal(self::TLS_KEY_ON_COMPLETION, $onCompletion);
	}

	/**
	 * Returns the DER key the client data must be signed with.
	 *
	 * @throws VerifyLoginException
	 */
	public static function verify(string $chainJwt, string $proxyKeyDer, string $clientDataJwt) : string{
		$claims = AuthJwtHelper::validateLegacyAuthToken($chainJwt, $proxyKeyDer);
		if(!isset($claims->identityPublicKey)){
			throw new VerifyLoginException("Missing identityPublicKey in chain link", KnownTranslationFactory::quark_disconnect_invalidSession_missingKey());
		}
		$identityKey = base64_decode($claims->identityPublicKey, true);
		if($identityKey === false){
			throw new VerifyLoginException("Invalid identityPublicKey: base64 error decoding");
		}
		AuthJwtHelper::validateSelfSignedToken($clientDataJwt, $identityKey);
		return $identityKey;
	}

	public function onRun() : void{
		try{
			$this->clientPublicKeyDer = self::verify($this->chainJwt, $this->proxyKeyDer, $this->clientDataJwt);
			$this->error = null;
		}catch(VerifyLoginException $e){
			$disconnectMessage = $e->getDisconnectMessage();
			$this->error = $disconnectMessage instanceof Translatable ? new NonThreadSafeValue($disconnectMessage) : $disconnectMessage;
		}
	}

	public function onCompletion() : void{
		/**
		 * @var \Closure $callback
		 * @phpstan-var \Closure(bool, bool, Translatable|string|null, ?string) : void $callback
		 */
		$callback = $this->fetchLocal(self::TLS_KEY_ON_COMPLETION);
		$callback($this->xboxLive && $this->error === null, $this->authRequired, $this->error instanceof NonThreadSafeValue ? $this->error->deserialize() : $this->error, $this->clientPublicKeyDer);
	}
}
