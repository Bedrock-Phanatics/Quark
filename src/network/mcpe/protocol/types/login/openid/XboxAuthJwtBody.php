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

namespace quark\network\mcpe\protocol\types\login\openid;

use quark\network\mcpe\protocol\types\login\JwtBodyRfc7519;

/**
 * JsonMapper model for the Xbox Live auth JWT claims as of Bedrock 1.21.100
 */
final class XboxAuthJwtBody extends JwtBodyRfc7519{
	/** @required */
	public string $ipt; // Platform type

	/** @required */
	public string $pfcd; // PlayFab Creation Date / First PlayFab Title Account Login

	/** @required */
	public string $tid; // PlayFab Title ID

	/** @required */
	public string $mid; // the player's Minecraft ID, identifying the player in Minecraft's PlayFab namespace

	/** @required */
	public string $xid; // the player's Xbox Live User Id

	/** @required */
	public string $xname; // the player's Xbox Live gamertag

	public int $ap; // ??

	/** @required */
	public string $cpk; // the public key that was used to sign the "client properties" token

	public string $pid = ""; // PlayStation Network user ID

	public string $pname = ""; // PlayStation Network username
}
