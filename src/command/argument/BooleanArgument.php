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
use function strtolower;

/**
 * @phpstan-extends Argument<bool>
 */
final class BooleanArgument extends Argument{

	public function parse(string $input, CommandSender $sender) : bool{
		return match(strtolower($input)){
			"true" => true,
			"false" => false,
			default => throw new ArgumentParseException(KnownTranslationFactory::quark_command_error_invalidBoolean($input))
		};
	}

	public function getKind() : ArgumentKind{
		return ArgumentKind::ENUM;
	}

	public function getEnumValues() : array{
		return ["true", "false"];
	}
}
