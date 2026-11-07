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

namespace quark\command\internal;

use quark\command\utils\CommandException;
use quark\lang\Translatable;

/**
 * @internal
 */
final class CommandInputException extends CommandException{

	/**
	 * @param Translatable[]|string[] $usages
	 * @phpstan-param list<Translatable|string> $usages
	 */
	public function __construct(
		private Translatable|string|null $userMessage,
		private array $usages
	){
		parent::__construct($userMessage instanceof Translatable ? $userMessage->getText() : ($userMessage ?? "Invalid command input"));
	}

	public function getUserMessage() : Translatable|string|null{
		return $this->userMessage;
	}

	/**
	 * @return Translatable[]|string[]
	 * @phpstan-return list<Translatable|string>
	 */
	public function getUsages() : array{
		return $this->usages;
	}
}
