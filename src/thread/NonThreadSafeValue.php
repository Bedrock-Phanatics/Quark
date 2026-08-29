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

namespace quark\thread;

use pmmp\thread\ThreadSafe;
use function get_debug_type;
use function igbinary_serialize;
use function igbinary_unserialize;

/**
 * This class automatically serializes values which can't be shared between threads.
 * This class does NOT enable sharing the variable between threads. Each call to deserialize() will return a new copy
 * of the variable.
 *
 * @phpstan-template TValue
 */
final class NonThreadSafeValue extends ThreadSafe{
	private string $variable;

	/**
	 * @phpstan-param TValue $variable
	 */
	public function __construct(
		mixed $variable
	){
		$this->variable = igbinary_serialize($variable) ?? throw new \InvalidArgumentException("Cannot serialize variable of type " . get_debug_type($variable));
	}

	/**
	 * Returns a deserialized copy of the original variable.
	 *
	 * @phpstan-return TValue
	 */
	public function deserialize() : mixed{
		return igbinary_unserialize($this->variable);
	}
}
