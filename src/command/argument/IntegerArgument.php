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
use function preg_match;
use function strcmp;
use function strlen;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

/**
 * @phpstan-extends Argument<int>
 */
final class IntegerArgument extends Argument{

	/**
	 * @throws \InvalidArgumentException
	 */
	public function __construct(
		string $name,
		private int $min = PHP_INT_MIN,
		private int $max = PHP_INT_MAX,
		bool $optional = false
	){
		if($min > $max){
			throw new \InvalidArgumentException("Minimum $min of argument \"$name\" is greater than maximum $max");
		}
		parent::__construct($name, $optional);
	}

	public function parse(string $input, CommandSender $sender) : int{
		if(preg_match('/\A([+-]?)0*([0-9]+)\z/', $input, $matches) !== 1){
			throw new ArgumentParseException(KnownTranslationFactory::quark_command_error_invalidInteger($input));
		}
		$negative = $matches[1] === "-";
		$digits = $matches[2];

		//compare digits before casting, php saturates out of range integer strings
		$limit = $negative ? "9223372036854775808" : "9223372036854775807";
		if(strlen($digits) > 19 || (strlen($digits) === 19 && strcmp($digits, $limit) > 0)){
			throw $negative ? $this->tooSmall($input) : $this->tooBig($input);
		}
		if(!$negative){
			$value = (int) $digits;
		}elseif($digits === "9223372036854775808"){
			$value = PHP_INT_MIN;
		}else{
			$value = -(int) $digits;
		}

		if($value < $this->min){
			throw $this->tooSmall($input);
		}
		if($value > $this->max){
			throw $this->tooBig($input);
		}
		return $value;
	}

	public function matches(string $token, int $slot = 0) : bool{
		return preg_match('/\A[+-]?[0-9]+\z/', $token) === 1;
	}

	public function getKind() : ArgumentKind{
		return ArgumentKind::INTEGER;
	}

	public function getValueType() : string{
		return "int";
	}

	private function tooSmall(string $input) : ArgumentParseException{
		return new ArgumentParseException(KnownTranslationFactory::commands_generic_num_tooSmall($input, (string) $this->min));
	}

	private function tooBig(string $input) : ArgumentParseException{
		return new ArgumentParseException(KnownTranslationFactory::commands_generic_num_tooBig($input, (string) $this->max));
	}
}
