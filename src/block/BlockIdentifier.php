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

namespace quark\block;

use quark\block\tile\Tile;
use quark\utils\Utils;

class BlockIdentifier{
	/**
	 * @phpstan-param class-string<Tile>|null $tileClass
	 */
	public function __construct(
		private int $blockTypeId,
		private ?string $tileClass = null
	){
		if($blockTypeId < 0){
			throw new \InvalidArgumentException("Block type ID may not be negative");
		}
		if($tileClass !== null){
			Utils::testValidInstance($tileClass, Tile::class);
		}
	}

	public function getBlockTypeId() : int{ return $this->blockTypeId; }

	/**
	 * @phpstan-return class-string<Tile>|null
	 */
	public function getTileClass() : ?string{
		return $this->tileClass;
	}
}
