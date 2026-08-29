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

final class AuthKeyring{

	/**
	 * @param string[] $keys
	 * @phpstan-param array<string, string> $keys
	 */
	public function __construct(
		private string $issuer,
		private array $keys
	){}

	public function getIssuer() : string{ return $this->issuer; }

	/**
	 * Returns a (raw) DER public key associated with the given key ID
	 */
	public function getKey(string $keyId) : ?string{
		return $this->keys[$keyId] ?? null;
	}
}
