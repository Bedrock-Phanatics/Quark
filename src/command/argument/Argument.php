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
 * @phpstan-template-covariant TValue
 */
abstract class Argument{

	/**
	 * @throws \InvalidArgumentException
	 */
	public function __construct(
		private string $name,
		private bool $optional = false
	){
		if(preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $name) !== 1){
			throw new \InvalidArgumentException("Invalid argument name \"$name\"");
		}
	}

	final public function getName() : string{
		return $this->name;
	}

	final public function isOptional() : bool{
		return $this->optional;
	}

	/**
	 * Parses the value of a single-token argument.
	 *
	 * @phpstan-return TValue
	 * @throws ArgumentParseException
	 */
	abstract public function parse(string $input, CommandSender $sender) : mixed;

	/**
	 * Parses the value from the tokens starting at $offset. Arguments wider than one token override this.
	 *
	 * @param string[] $tokens
	 * @phpstan-param list<string> $tokens
	 * @phpstan-return TValue
	 * @throws ArgumentParseException
	 */
	public function parseTokens(array $tokens, int $offset, CommandSender $sender) : mixed{
		return $this->parse($tokens[$offset], $sender);
	}

	/**
	 * Number of tokens consumed. Only greedy text takes a variable amount.
	 */
	public function getWidth() : int{
		return 1;
	}

	/**
	 * Cheap shape check used to choose between overloads before parsing. It must not have side effects and must
	 * accept everything that {@link Argument::parse()} accepts.
	 */
	public function matches(string $token, int $slot = 0) : bool{
		return true;
	}

	public function getKind() : ArgumentKind{
		return ArgumentKind::WORD;
	}

	/**
	 * Whether a plain number can be a valid value. Arguments that never accept one let overloads with a number in the
	 * same position coexist. Their {@link Argument::matches()} must reject numbers.
	 */
	public function allowsNumbers() : bool{
		return true;
	}

	/**
	 * Name shown in usage messages.
	 */
	public function getUsageLabel() : string{
		return $this->name;
	}

	/**
	 * Lowercase values accepted by this argument, or null when it accepts open input. Used to detect ambiguous
	 * overloads at registration.
	 *
	 * @return string[]|null
	 * @phpstan-return list<string>|null
	 */
	public function getChoices() : ?array{
		return null;
	}

	public function getEnum() : ?CommandEnum{
		return null;
	}

	public function getSoftEnum() : ?SoftEnum{
		return null;
	}

	/**
	 * PHP type of the parsed value, checked against typed handlers when the command is registered.
	 */
	public function getValueType() : string{
		return "mixed";
	}
}
