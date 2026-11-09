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

use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use function is_finite;
use function preg_match;
use function strlen;
use function strtolower;

/**
 * A length of time written as a number with an optional unit (ms, s, m, h or d, seconds by default), returned in
 * seconds. "1.5s", "5m" and "60" are valid.
 *
 * @phpstan-extends Argument<float>
 */
final class DurationArgument extends Argument{
	private const int MAX_LENGTH = 24;
	private const string PATTERN = '/\A([0-9]+(?:\.[0-9]+)?)(ms|s|m|h|d)?\z/';

	/**
	 * @param float $min smallest accepted number of seconds
	 * @param float $max largest accepted number of seconds
	 * @throws \InvalidArgumentException
	 */
	public function __construct(
		string $name,
		private float $min = 0.0,
		private float $max = 86400.0,
		bool $optional = false
	){
		if(!is_finite($min) || !is_finite($max) || $min > $max){
			throw new \InvalidArgumentException("Invalid duration bounds for argument \"$name\"");
		}
		parent::__construct($name, $optional);
	}

	public function parse(string $input, CommandSender $sender) : float{
		if(strlen($input) > self::MAX_LENGTH || preg_match(self::PATTERN, strtolower($input), $matches) !== 1){
			throw new ArgumentParseException(KnownTranslationFactory::quark_command_error_invalidValue($input));
		}
		$seconds = (float) $matches[1] * match($matches[2] ?? ""){
			"ms" => 0.001,
			"", "s" => 1.0,
			"m" => 60.0,
			"h" => 3600.0,
			"d" => 86400.0
		};
		if($seconds < $this->min){
			throw new ArgumentParseException(KnownTranslationFactory::commands_generic_num_tooSmall($input, $this->min . "s"));
		}
		if($seconds > $this->max){
			throw new ArgumentParseException(KnownTranslationFactory::commands_generic_num_tooBig($input, $this->max . "s"));
		}
		return $seconds;
	}

	public function matches(string $token, int $slot = 0) : bool{
		return strlen($token) <= self::MAX_LENGTH && preg_match(self::PATTERN, strtolower($token)) === 1;
	}

	public function getValueType() : string{
		return "float";
	}
}
