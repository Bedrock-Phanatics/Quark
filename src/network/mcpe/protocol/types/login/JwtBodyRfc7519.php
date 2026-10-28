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

namespace quark\network\mcpe\protocol\types\login;

/**
 * Model class for JsonMapper which describes the RFC7519 standard fields in a JWT. Any of these fields might not be
 * provided.
 */
class JwtBodyRfc7519{
	public string $iss;
	public string $sub;
	/** @var mixed */
	public $aud;
	public int $exp;
	public int $nbf;
	public int $iat;
	public string $jti;
}
