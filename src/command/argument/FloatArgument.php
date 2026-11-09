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

/**
 * @phpstan-extends Argument<float>
 */
final class FloatArgument extends Argument{
	public const string PATTERN = '/\A[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?\z/';


	/**
	 * @throws \InvalidArgumentException
	 */
	public function __construct(
		string $name,
		private ?float $min = null,
		private ?float $max = null,
		bool $optional = false
	){
		if(($min !== null && !is_finite($min)) || ($max !== null && !is_finite($max))){
			throw new \InvalidArgumentException("Bounds of argument \"$name\" must be finite");
		}
		if($min !== null && $max !== null && $min > $max){
			throw new \InvalidArgumentException("Minimum $min of argument \"$name\" is greater than maximum $max");
		}
		parent::__construct($name, $optional);
	}

	public function parse(string $input, CommandSender $sender) : float{
		if(!$this->matches($input)){
			throw new ArgumentParseException(KnownTranslationFactory::quark_command_error_invalidNumber($input));
		}
		$value = (float) $input;
		if(!is_finite($value)){
			throw new ArgumentParseException(KnownTranslationFactory::quark_command_error_invalidNumber($input));
		}
		if($this->min !== null && $value < $this->min){
			throw new ArgumentParseException(KnownTranslationFactory::commands_generic_num_tooSmall($input, (string) $this->min));
		}
		if($this->max !== null && $value > $this->max){
			throw new ArgumentParseException(KnownTranslationFactory::commands_generic_num_tooBig($input, (string) $this->max));
		}
		return $value;
	}

	public function matches(string $token, int $slot = 0) : bool{
		return preg_match(self::PATTERN, $token) === 1;
	}

	public function getKind() : ArgumentKind{
		return ArgumentKind::FLOAT;
	}

	public function getValueType() : string{
		return "float";
	}
}
