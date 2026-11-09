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

use quark\command\argument\SoftEnum;
use quark\command\CommandSyntax;

/**
 * Routing data computed once when a command is registered.
 *
 * @internal
 */
final class PreparedCommand{
	/**
	 * @param CommandSyntax[] $syntaxes  all syntaxes in deterministic order
	 * @param SoftEnum[]      $softEnums
	 * @phpstan-param list<CommandSyntax>     $syntaxes
	 * @phpstan-param array<string, SoftEnum> $softEnums
	 */
	public function __construct(
		public readonly SyntaxNode $root,
		public readonly array $syntaxes,
		public readonly array $softEnums
	){}
}
