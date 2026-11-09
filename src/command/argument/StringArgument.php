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
use function preg_match;

/**
 * A greedy argument takes the rest of the line exactly as typed, including quotes, escapes and spacing. With
 * $numbers off, a plain number is not a valid value, which lets a number argument share its position.
 *
 * @phpstan-extends Argument<string>
 */
final class StringArgument extends Argument{
	public function __construct(string $name, bool $optional = false, private bool $greedy = false, private bool $numbers = true){
		parent::__construct($name, $optional);
	}

	public function parse(string $input, CommandSender $sender) : string{
		return $input;
	}

	public function matches(string $token, int $slot = 0) : bool{
		return $this->numbers || preg_match(FloatArgument::PATTERN, $token) !== 1;
	}

	public function allowsNumbers() : bool{
		return $this->numbers;
	}

	public function getKind() : ArgumentKind{
		return $this->greedy ? ArgumentKind::TEXT : ArgumentKind::WORD;
	}

	public function getValueType() : string{
		return "string";
	}
}
