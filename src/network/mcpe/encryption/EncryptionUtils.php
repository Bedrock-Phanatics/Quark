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

namespace quark\network\mcpe\encryption;

use quark\network\mcpe\JwtUtils;
use quark\utils\Utils;
use function base64_encode;
use function bin2hex;
use function gmp_init;
use function gmp_strval;
use function hex2bin;
use function openssl_digest;
use function openssl_error_string;
use function openssl_pkey_derive;
use function openssl_pkey_get_details;
use function str_pad;
use const STR_PAD_LEFT;

final class EncryptionUtils{

	private function __construct(){
		//NOOP
	}

	private static function validateKey(\OpenSSLAsymmetricKey $key) : void{
		$keyDetails = Utils::assumeNotFalse(openssl_pkey_get_details($key));
		if(!isset($keyDetails["ec"]["curve_name"])){
			throw new \InvalidArgumentException("Key must be an EC key");
		}
		$curveName = $keyDetails["ec"]["curve_name"];
		if($curveName !== JwtUtils::BEDROCK_SIGNING_KEY_CURVE_NAME){
			throw new \InvalidArgumentException("Key must belong to the " . JwtUtils::BEDROCK_SIGNING_KEY_CURVE_NAME . " elliptic curve, got $curveName");
		}
	}

	public static function generateSharedSecret(\OpenSSLAsymmetricKey $localPriv, \OpenSSLAsymmetricKey $remotePub) : \GMP{
		self::validateKey($localPriv);
		self::validateKey($remotePub);
		$hexSecret = openssl_pkey_derive($remotePub, $localPriv, 48);
		if($hexSecret === false){
			throw new \InvalidArgumentException("Failed to derive shared secret: " . openssl_error_string());
		}
		return gmp_init(bin2hex($hexSecret), 16);
	}

	public static function generateKey(\GMP $secret, string $salt) : string{
		return Utils::assumeNotFalse(openssl_digest($salt . hex2bin(str_pad(gmp_strval($secret, 16), 96, "0", STR_PAD_LEFT)), 'sha256', true));
	}

	public static function generateServerHandshakeJwt(\OpenSSLAsymmetricKey $serverPriv, string $salt) : string{
		$derPublicKey = JwtUtils::emitDerPublicKey($serverPriv);
		return JwtUtils::create(
			[
				"x5u" => base64_encode($derPublicKey),
				"alg" => "ES384"
			],
			[
				"salt" => base64_encode($salt)
			],
			$serverPriv
		);
	}
}
