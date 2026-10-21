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

namespace quark\network\mcpe\bedwire;

use quark\network\TransportIdentityException;
use quark\network\TransportIdentityKey;
use function hash;
use function hash_equals;

/**
 * The SHA-256 of the DER public key a client proved it holds during NetherNet signaling.
 */
final class BedwireIdentityKey implements TransportIdentityKey{

	public function __construct(
		private ?string $digest
	){}

	public function getKey() : ?string{
		return $this->digest;
	}

	public function verify(string $clientPublicKey) : void{
		if($this->digest === null){
			throw new TransportIdentityException("Missing NetherNet identity key");
		}
		if(!hash_equals($this->digest, hash("sha256", $clientPublicKey, binary: true))){
			throw new TransportIdentityException("Client public key does not match NetherNet identity key");
		}
	}
}
