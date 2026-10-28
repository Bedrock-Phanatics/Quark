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

namespace quark\network\mcpe\protocol\types;

use Bedwire\Nbt;
use pocketmine\nbt\tag\Tag;
use pocketmine\nbt\TreeRoot;

/**
 * @phpstan-template TTagType of Tag
 */
final class CacheableNbt{
	private ?string $encodedNbt = null;

	/**
	 * @phpstan-param TTagType $nbtRoot
	 */
	public function __construct(
		private Tag $nbtRoot
	){}

	/**
	 * @phpstan-return TTagType
	 */
	public function getRoot() : Tag{
		return $this->nbtRoot;
	}

	public function getEncodedNbt() : string{
		return $this->encodedNbt ?? ($this->encodedNbt = Nbt::write(new TreeRoot($this->nbtRoot), Nbt::NETWORK));
	}
}
