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

namespace quark\data\bedrock\block\convert\property;

use pocketmine\utils\Limits;
use quark\data\bedrock\block\convert\BlockStateReader;
use quark\data\bedrock\block\convert\BlockStateWriter;

/**
 * @phpstan-template TBlock of object
 * @phpstan-implements Property<TBlock>
 */
final class IntProperty implements Property{
	/**
	 * @phpstan-param \Closure(TBlock) : int        $getter
	 * @phpstan-param \Closure(TBlock, int) : mixed $setter
	 */
	public function __construct(
		private string $name,
		private int $min,
		private int $max,
		private \Closure $getter,
		private \Closure $setter,
		private int $offset = 0
	){
		if($min > $max){
			throw new \InvalidArgumentException("Min value cannot be greater than max value");
		}
	}

	public function getName() : string{ return $this->name; }

	/**
	 * @phpstan-return self<object>
	 */
	public static function unused(string $name, int $serializedValue) : self{
		return new self($name, Limits::INT32_MIN, Limits::INT32_MAX, fn() => $serializedValue, fn() => null);
	}

	public function deserialize(object $block, BlockStateReader $in) : void{
		$value = $in->readBoundedInt($this->name, $this->min, $this->max);
		($this->setter)($block, $value + $this->offset);
	}

	public function serialize(object $block, BlockStateWriter $out) : void{
		$value = ($this->getter)($block);
		$out->writeInt($this->name, $value - $this->offset);
	}
}
