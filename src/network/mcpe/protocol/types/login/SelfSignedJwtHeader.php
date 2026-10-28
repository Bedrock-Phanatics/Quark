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
 * JsonMapper model for headers of JWTs used in self-signed authentication and for the client data JWT header.
 */
final class SelfSignedJwtHeader{
	/** @required */
	public string $alg;
	/** @required */
	public string $x5u;

	/**
	 * As of 2023-03-29, this field suddenly started appearing in JWTs returned by the Mojang authentication API.
	 * It's unclear whether this was intended, but it is part of the JWT spec, so it's not a problem to accept it.
	 */
	public string $x5t;
}
