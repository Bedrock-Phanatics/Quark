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

namespace quark\command\argument;

use quark\lang\KnownTranslationFactory;
use function is_finite;
use function preg_match;

/**
 * A number that is either absolute or an offset from a base value, written like "5", "~" or "~-2.5".
 */
final class RelativeFloat{
	private const string PATTERN = '/\A(~)?([+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?)?\z/';

	public function __construct(
		public readonly float $value,
		public readonly bool $relative
	){}

	public static function isValid(string $input) : bool{
		return preg_match(self::PATTERN, $input, $m) === 1 && (($m[1] ?? "") !== "" || ($m[2] ?? "") !== "");
	}

	/**
	 * @throws ArgumentParseException
	 */
	public static function parse(string $input) : self{
		if(!self::isValid($input)){
			throw new ArgumentParseException(KnownTranslationFactory::quark_command_error_invalidNumber($input));
		}
		preg_match(self::PATTERN, $input, $m);
		$value = (float) ($m[2] ?? "0");
		if(!is_finite($value)){
			throw new ArgumentParseException(KnownTranslationFactory::quark_command_error_invalidNumber($input));
		}
		return new self($value, ($m[1] ?? "") !== "");
	}

	public function resolve(float $base) : float{
		return $this->relative ? $base + $this->value : $this->value;
	}
}
