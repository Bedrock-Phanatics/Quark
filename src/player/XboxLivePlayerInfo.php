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

namespace quark\player;

use quark\entity\Skin;
use Ramsey\Uuid\UuidInterface;

/**
 * Encapsulates player info specific to players who are authenticated with XBOX Live.
 */
final class XboxLivePlayerInfo extends PlayerInfo{
	private string $xuid;

	public function __construct(string $xuid, string $username, UuidInterface $uuid, Skin $skin, string $locale, array $extraData = []){
		parent::__construct($username, $uuid, $skin, $locale, $extraData);
		$this->xuid = $xuid;
	}

	public function getXuid() : string{
		return $this->xuid;
	}

	/**
	 * Returns a new PlayerInfo with XBL player info stripped. This is used to ensure that non-XBL players can't spoof
	 * XBL data.
	 */
	public function withoutXboxData() : PlayerInfo{
		return new PlayerInfo(
			$this->getUsername(),
			$this->getUuid(),
			$this->getSkin(),
			$this->getLocale(),
			$this->getExtraData()
		);
	}
}
