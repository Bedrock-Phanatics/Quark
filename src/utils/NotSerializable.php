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

namespace quark\utils;

trait NotSerializable{

	/** @return mixed[] */
	final public function __serialize() : array{
		throw new \LogicException("Serialization of " . static::class . " objects is not allowed");
	}

	/** @param mixed[] $data */
	final public function __unserialize(array $data) : void{
		throw new \LogicException("Unserialization of " . static::class . " objects is not allowed");
	}
}
