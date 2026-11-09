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

namespace quark\command\defaults;

use pocketmine\utils\Limits;
use quark\command\argument\Argument;
use quark\command\argument\ArgumentParseException;
use quark\command\argument\IntegerArgument;
use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use function preg_match;

/**
 * A whole number of experience levels written with an "L" suffix, such as "5L" or "-3L".
 *
 * @phpstan-extends Argument<int>
 */
final class XpLevelsArgument extends Argument{
	private const PATTERN = '/\A([+-]?[0-9]+)[lL]\z/';

	private IntegerArgument $number;

	public function __construct(string $name, bool $optional = false){
		parent::__construct($name, $optional);
		$this->number = new IntegerArgument($name, Limits::INT32_MIN, Limits::INT32_MAX);
	}

	public function parse(string $input, CommandSender $sender) : int{
		if(preg_match(self::PATTERN, $input, $matches) !== 1){
			throw new ArgumentParseException(KnownTranslationFactory::quark_command_error_invalidNumber($input));
		}
		return $this->number->parse($matches[1], $sender);
	}

	public function matches(string $token, int $slot = 0) : bool{
		return preg_match(self::PATTERN, $token) === 1;
	}

	public function allowsNumbers() : bool{
		return false;
	}

	public function getUsageLabel() : string{
		return $this->getName() . "(L)";
	}

	public function getValueType() : string{
		return "int";
	}
}
