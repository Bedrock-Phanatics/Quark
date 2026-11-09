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

use quark\command\CommandSyntax;

/**
 * @internal
 */
final class SyntaxNode{
	/** @phpstan-var array<string, SyntaxNode> */
	public array $children = [];
	/**
	 * Sorted so that the first match is the same whatever order the syntaxes were declared in.
	 *
	 * @var CommandSyntax[]
	 * @phpstan-var list<CommandSyntax>
	 */
	public array $syntaxes = [];
}
