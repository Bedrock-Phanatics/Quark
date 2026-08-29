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

namespace quark\world\light;

final class LightPropagationContext{

	/** @phpstan-var \SplQueue<array{int, int, int}> */
	public \SplQueue $spreadQueue;
	/**
	 * @var int[]|true[]
	 * @phpstan-var array<int, int|true>
	 */
	public array $spreadVisited = [];

	/** @phpstan-var \SplQueue<array{int, int, int, int}> */
	public \SplQueue $removalQueue;
	/**
	 * @var true[]
	 * @phpstan-var array<int, true>
	 */
	public array $removalVisited = [];

	public function __construct(){
		$this->removalQueue = new \SplQueue();
		$this->spreadQueue = new \SplQueue();
	}
}
