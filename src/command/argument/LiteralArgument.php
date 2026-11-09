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
use function strtolower;

/**
 * A fixed word that must be typed in its position. It is matched case-insensitively and is not passed to handlers.
 *
 * @phpstan-extends Argument<string>
 */
final class LiteralArgument extends Argument{

	/**
	 * @throws \InvalidArgumentException
	 */
	public function __construct(private string $literal){
		if(preg_match('/\A[a-z0-9_-]+\z/', $literal) !== 1){
			throw new \InvalidArgumentException("Literal \"$literal\" must be a lowercase word");
		}
		parent::__construct("_literal");
	}

	public function getLiteral() : string{
		return $this->literal;
	}

	public function parse(string $input, CommandSender $sender) : string{
		if(strtolower($input) !== $this->literal){
			throw new ArgumentParseException("Expected \"$this->literal\"");
		}
		return $this->literal;
	}

	public function matches(string $token, int $slot = 0) : bool{
		return strtolower($token) === $this->literal;
	}

	public function getKind() : ArgumentKind{
		return ArgumentKind::LITERAL;
	}

	public function getChoices() : array{
		return [$this->literal];
	}

	public function getValueType() : string{
		return "string";
	}
}
