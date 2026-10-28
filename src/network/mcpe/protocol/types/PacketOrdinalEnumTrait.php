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

use quark\network\mcpe\protocol\PacketDecodeException;
use function count;

/**
 * Trait for string-backed enums serialized in packets, with ordinal-based case resolution.
 * Provides helpers to read, validate and properly bail on invalid values.
 */
trait PacketOrdinalEnumTrait{

	/**
	 * @throws PacketDecodeException
	 */
	public static function fromPacket(string $value) : self{
		$enum = self::tryFrom($value);
		if($enum === null){
			throw new PacketDecodeException("Invalid raw case '$value' for " . static::class);
		}

		return $enum;
	}

	public function toOrdinal() : int{
		/** @var array<string, int> $ordinals */
		static $ordinals = [];

		if(count($ordinals) === 0){
			foreach(self::cases() as $i => $case){
				$ordinals[$case->name] = $i;
			}
		}

		return $ordinals[$this->name];
	}

	/**
	 * @throws PacketDecodeException
	 */
	public static function fromOrdinal(int $ordinal) : static{
		/**
		 * @var static[] $cases
		 * @phpstan-var list<static> $cases
		 */
		static $cases = [];

		if(count($cases) === 0){
			$cases = self::cases();
		}

		return $cases[$ordinal] ?? throw new PacketDecodeException("Invalid ordinal value $ordinal for " . static::class);
	}
}
