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

use pocketmine\math\Vector3;

final class RelativePosition{
	public function __construct(
		public readonly RelativeFloat $x,
		public readonly RelativeFloat $y,
		public readonly RelativeFloat $z
	){}

	public function resolve(Vector3 $base) : Vector3{
		return new Vector3($this->x->resolve($base->x), $this->y->resolve($base->y), $this->z->resolve($base->z));
	}
}
