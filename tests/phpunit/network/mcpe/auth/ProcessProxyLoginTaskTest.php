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
use quark\network\mcpe\bedwire\BedwireIdentityKey;
use quark\network\mcpe\JwtUtils;
use quark\network\TransportIdentityException;
use function base64_encode;
use function hash;
use function openssl_pkey_new;
use function time;
use const OPENSSL_KEYTYPE_EC;

class ProcessProxyLoginTaskTest extends TestCase{

	private static function newKey() : \OpenSSLAsymmetricKey{
		$key = openssl_pkey_new(["private_key_type" => OPENSSL_KEYTYPE_EC, "curve_name" => "secp384r1"]);
		self::assertNotFalse($key);
		return $key;
	}

	/**
	 * Mirrors the managed login Bifrost sends.
	 *
	 * @return array{string, string, string}
	 */
	private static function proxyLogin(\OpenSSLAsymmetricKey $signer, ?string $claimedKeyDer = null, int $expiry = 3600) : array{
		$keyDer = JwtUtils::emitDerPublicKey($signer);
		$header = ["alg" => "ES384", "x5u" => base64_encode($claimedKeyDer ?? $keyDer)];
		$chain = JwtUtils::create($header, [
			"exp" => time() + $expiry,
			"identityPublicKey" => base64_encode($keyDer),
			"extraData" => ["displayName" => "Steve", "identity" => "8b2e0a1c-3b6f-3c51-9d0e-4f2a1b6c7d8e", "XUID" => "2535400000000000"],
		], $signer);
		$clientData = JwtUtils::create($header, ["DeviceOS" => 7], $signer);
		return [$chain, $keyDer, $clientData];
	}

	public function testAcceptsChainSignedByProxy() : void{
		$proxy = self::newKey();
		[$chain, $keyDer, $clientData] = self::proxyLogin($proxy);
		self::assertSame($keyDer, ProcessProxyLoginTask::verify($chain, $keyDer, $clientData));
	}

	public function testRejectsChainFromAnotherKey() : void{
		$proxy = self::newKey();
		[$chain, , $clientData] = self::proxyLogin(self::newKey());
		$this->expectException(VerifyLoginException::class);
		ProcessProxyLoginTask::verify($chain, JwtUtils::emitDerPublicKey($proxy), $clientData);
	}

	public function testRejectsForgedSignatureClaimingProxyKey() : void{
		$proxyDer = JwtUtils::emitDerPublicKey(self::newKey());
		[$chain, , $clientData] = self::proxyLogin(self::newKey(), $proxyDer);
		$this->expectException(VerifyLoginException::class);
		ProcessProxyLoginTask::verify($chain, $proxyDer, $clientData);
	}

	public function testRejectsClientDataFromAnotherKey() : void{
		$proxy = self::newKey();
		[$chain, $keyDer, ] = self::proxyLogin($proxy);
		[, , $otherClientData] = self::proxyLogin(self::newKey());
		$this->expectException(VerifyLoginException::class);
		ProcessProxyLoginTask::verify($chain, $keyDer, $otherClientData);
	}

	public function testRejectsExpiredChain() : void{
		$proxy = self::newKey();
		[$chain, $keyDer, $clientData] = self::proxyLogin($proxy, expiry: -86400);
		$this->expectException(VerifyLoginException::class);
		ProcessProxyLoginTask::verify($chain, $keyDer, $clientData);
	}

	public function testTransportKeyMatchesProxyKey() : void{
		$keyDer = JwtUtils::emitDerPublicKey(self::newKey());
		(new BedwireIdentityKey(hash("sha256", $keyDer, binary: true)))->verify($keyDer);
		$this->expectException(TransportIdentityException::class);
		(new BedwireIdentityKey(hash("sha256", $keyDer, binary: true)))->verify(JwtUtils::emitDerPublicKey(self::newKey()));
	}
}
