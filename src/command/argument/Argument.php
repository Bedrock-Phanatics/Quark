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
	 * @phpstan-return TValue
	 * @throws ArgumentParseException
	 */
	abstract public function parse(string $input, CommandSender $sender) : mixed;

	public function getKind() : ArgumentKind{
		return ArgumentKind::WORD;
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getEnumValues() : array{
		return [];
	}
}
