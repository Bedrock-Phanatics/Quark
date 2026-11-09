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
use function implode;

/**
 * @phpstan-extends Argument<mixed>
 */
final class EnumArgument extends Argument{
	private CommandEnum $enum;

	/**
	 * @param CommandEnum|string[]|string $enum an enum definition, a list of words or a native enum class
	 * @phpstan-param CommandEnum|list<string>|class-string<\UnitEnum> $enum
	 * @throws \InvalidArgumentException
	 */
	public function __construct(string $name, CommandEnum|array|string $enum, bool $optional = false){
		$this->enum = match(true){
			$enum instanceof CommandEnum => $enum,
			is_array($enum) => CommandEnum::fromValues($name, $enum),
			default => CommandEnum::fromCases($enum)
		};
		parent::__construct($name, $optional);
	}

	public function parse(string $input, CommandSender $sender) : mixed{
		return $this->enum->find($input) ??
			throw new ArgumentParseException(KnownTranslationFactory::quark_command_error_invalidEnum($input, implode(", ", $this->enum->getValues())));
	}

	public function matches(string $token, int $slot = 0) : bool{
		return $this->enum->find($token) !== null;
	}

	public function getKind() : ArgumentKind{
		return ArgumentKind::ENUM;
	}

	public function getChoices() : array{
		return $this->enum->getValues();
	}

	public function getEnum() : CommandEnum{
		return $this->enum;
	}

	public function getValueType() : string{
		return $this->enum->getValueType();
	}
}
