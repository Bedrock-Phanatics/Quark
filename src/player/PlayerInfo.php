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
use quark\utils\TextFormat;
use Ramsey\Uuid\UuidInterface;

/**
 * Encapsulates data needed to create a player.
 */
class PlayerInfo{
	/**
	 * @param mixed[] $extraData
	 * @phpstan-param array<string, mixed> $extraData
	 */
	public function __construct(
		private string $username,
		private UuidInterface $uuid,
		private Skin $skin,
		private string $locale,
		private array $extraData = []
	){
		$this->username = TextFormat::clean($username);
	}

	public function getUsername() : string{
		return $this->username;
	}

	public function getUuid() : UuidInterface{
		return $this->uuid;
	}

	public function getSkin() : Skin{
		return $this->skin;
	}

	public function getLocale() : string{
		return $this->locale;
	}

	/**
	 * @return mixed[]
	 * @phpstan-return array<string, mixed>
	 */
	public function getExtraData() : array{
		return $this->extraData;
	}
}
