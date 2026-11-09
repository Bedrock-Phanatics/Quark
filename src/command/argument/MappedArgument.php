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

/**
 * Turns one word into a value with a closure, for lookups that are not a fixed enum such as items or effects. The
 * closure returns null for unknown input or throws {@link ArgumentParseException} with its own message.
 *
 * @phpstan-template TValue of object
 * @phpstan-extends Argument<TValue>
 */
final class MappedArgument extends Argument{

	/**
	 * @phpstan-param \Closure(string, CommandSender) : (TValue|null) $mapper
	 * @phpstan-param class-string<TValue> $type
	 */
	public function __construct(
		string $name,
		private \Closure $mapper,
		private string $type,
		bool $optional = false,
		private ?CommandEnum $suggestions = null
	){
		parent::__construct($name, $optional);
	}

	public function parse(string $input, CommandSender $sender) : object{
		return ($this->mapper)($input, $sender) ??
			throw new ArgumentParseException(KnownTranslationFactory::quark_command_error_invalidValue($input));
	}

	public function getKind() : ArgumentKind{
		return $this->suggestions !== null ? ArgumentKind::ENUM : ArgumentKind::WORD;
	}

	public function getEnum() : ?CommandEnum{
		return $this->suggestions;
	}

	public function getValueType() : string{
		return $this->type;
	}
}
