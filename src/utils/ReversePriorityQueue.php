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

/**
 * @phpstan-template TPriority of numeric
 * @phpstan-template TValue
 * @phpstan-extends \SplPriorityQueue<TPriority, TValue>
 */
class ReversePriorityQueue extends \SplPriorityQueue{

	/**
	 * @param mixed $priority1
	 * @param mixed $priority2
	 * @phpstan-param TPriority $priority1
	 * @phpstan-param TPriority $priority2
	 *
	 * @return int
	 */
	#[\ReturnTypeWillChange]
	public function compare($priority1, $priority2){
		//TODO: this will crash if non-numeric priorities are used
		return (int) -($priority1 - $priority2);
	}
}
