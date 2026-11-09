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

use quark\command\argument\Argument;
use quark\command\CommandSyntax;
use quark\lang\Translatable;

/**
 * Why a syntax did not accept the input. Only built on failure.
 *
 * @internal
 */
final class SyntaxFailure{
	/**
	 * @param Argument<mixed> $argument
	 * @param int             $start    token index of the argument, after the leading words
	 * @param int             $position token index that was rejected, used to pick the closest syntax
	 */
	public function __construct(
		public readonly CommandSyntax $syntax,
		public readonly Argument $argument,
		public readonly int $start,
		public readonly int $position,
		public readonly Translatable|string|null $message
	){}
}
