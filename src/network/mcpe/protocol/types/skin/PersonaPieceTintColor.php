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

namespace quark\network\mcpe\protocol\types\skin;

use pocketmine\color\Color;
use function count;

final class PersonaPieceTintColor{

	public const EXPECTED_COLOR_COUNT = 4;

	/**
	 * @param Color[] $colors
	 * @phpstan-param array{Color, Color, Color, Color} $colors
	 */
	public function __construct(
		private PersonaSkinPieceType $pieceType,
		private array $colors
	){
		if(count($this->colors) !== self::EXPECTED_COLOR_COUNT){
			throw new \InvalidArgumentException("Colors array must contain exactly " . self::EXPECTED_COLOR_COUNT . " Color objects");
		}
	}

	public function getPieceType() : PersonaSkinPieceType{
		return $this->pieceType;
	}

	/**
	 * @return Color[]
	 * @phpstan-return array{Color, Color, Color, Color}
	 */
	public function getColors() : array{
		return $this->colors;
	}
}
