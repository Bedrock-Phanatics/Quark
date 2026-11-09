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
 * @phpstan-extends Argument<RelativeFloat>
 */
final class RelativeFloatArgument extends Argument{

	public function parse(string $input, CommandSender $sender) : RelativeFloat{
		return RelativeFloat::parse($input);
	}

	public function matches(string $token, int $slot = 0) : bool{
		return RelativeFloat::isValid($token);
	}

	public function getKind() : ArgumentKind{
		return ArgumentKind::VALUE;
	}

	public function getValueType() : string{
		return RelativeFloat::class;
	}
}
