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

/**
 * Three tokens, "x y z", each absolute or relative to a base position.
 *
 * @phpstan-extends Argument<RelativePosition>
 */
final class PositionArgument extends Argument{

	public function parse(string $input, CommandSender $sender) : RelativePosition{
		throw new \LogicException("A position spans three tokens");
	}

	public function parseTokens(array $tokens, int $offset, CommandSender $sender) : RelativePosition{
		return new RelativePosition(
			RelativeFloat::parse($tokens[$offset]),
			RelativeFloat::parse($tokens[$offset + 1]),
			RelativeFloat::parse($tokens[$offset + 2])
		);
	}

	public function getWidth() : int{
		return 3;
	}

	public function matches(string $token, int $slot = 0) : bool{
		return RelativeFloat::isValid($token);
	}

	public function getKind() : ArgumentKind{
		return ArgumentKind::POSITION;
	}

	public function getValueType() : string{
		return RelativePosition::class;
	}
}
