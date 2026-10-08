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

namespace quark\pulse\internal;

/**
 * @internal
 */
final class PulseNode{
	/** @var array<int, self> */
	public array $children = [];
	public int $calls = 0;
	public int $total = 0;
	public int $self = 0;
	public int $max = 0;
	// Each parent path has its own node, including recursive calls.
	public int $started = 0;
	public int $childTime = 0;
	public int $scope = 0;

	public function __construct(
		public readonly int $id,
		public readonly int $zone,
		public readonly int $parent
	){}

	public function reset() : void{
		$this->calls = $this->total = $this->self = $this->max = 0;
	}
}
