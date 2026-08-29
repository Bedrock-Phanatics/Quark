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

namespace quark\data\bedrock\block\convert\property;

/**
 * @phpstan-template TValue
 * @phpstan-template TRaw of int|string
 */
interface StateMap{

	/**
	 * @phpstan-return array<TRaw, TValue>
	 */
	public function getRawToValueMap() : array;

	/**
	 * @phpstan-param TValue $value
	 * @phpstan-return TRaw
	 */
	public function valueToRaw(mixed $value) : int|string;

	/**
	 * @phpstan-param TRaw $raw
	 * @phpstan-return TValue|null
	 */
	public function rawToValue(int|string $raw) : mixed;

	/**
	 * @phpstan-param TValue $value
	 */
	public function printableValue(mixed $value) : string;
}
