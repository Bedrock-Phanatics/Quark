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
use function array_slice;
use function count;
use function implode;

/**
 * @phpstan-extends Argument<string>
 */
final class SoftEnumArgument extends Argument{
	private const int MAX_LISTED = 20;

	public function __construct(string $name, private SoftEnum $enum, bool $optional = false){
		parent::__construct($name, $optional);
	}

	public function parse(string $input, CommandSender $sender) : string{
		return $this->enum->find($input) ?? throw new ArgumentParseException(
			KnownTranslationFactory::quark_command_error_invalidEnum($input, $this->describeValues())
		);
	}

	public function matches(string $token, int $slot = 0) : bool{
		return $this->enum->has($token);
	}

	public function getKind() : ArgumentKind{
		return ArgumentKind::SOFT_ENUM;
	}

	public function getSoftEnum() : SoftEnum{
		return $this->enum;
	}

	public function getValueType() : string{
		return "string";
	}

	private function describeValues() : string{
		$values = $this->enum->getValues();
		$listed = implode(", ", array_slice($values, 0, self::MAX_LISTED));
		return count($values) > self::MAX_LISTED ? $listed . ", ..." : $listed;
	}
}
