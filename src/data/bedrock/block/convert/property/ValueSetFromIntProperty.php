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

use quark\data\bedrock\block\convert\BlockStateReader;
use quark\data\bedrock\block\convert\BlockStateWriter;
use quark\utils\AssumptionFailedError;

/**
 * @phpstan-template TBlock of object
 * @phpstan-template TOption of int|\UnitEnum
 * @phpstan-implements Property<TBlock>
 */
class ValueSetFromIntProperty implements Property{

	private int $maxValue = 0;

	/**
	 * @phpstan-param StateMap<TOption, int> $map
	 * @phpstan-param \Closure(TBlock) : array<TOption> $getter
	 * @phpstan-param \Closure(TBlock, array<TOption>) : mixed $setter
	 */
	public function __construct(
		private string $name,
		private StateMap $map,
		private \Closure $getter,
		private \Closure $setter
	){
		$flagsToCases = $this->map->getRawToValueMap();
		foreach($flagsToCases as $possibleFlag => $option){
			if(($this->maxValue & $possibleFlag) !== 0){
				foreach($flagsToCases as $otherFlag => $otherOption){
					if(($possibleFlag & $otherFlag) === $otherFlag && $otherOption !== $option){
						$printableOption = $this->map->printableValue($option);
						$printableOtherOption = $this->map->printableValue($otherOption);
						throw new \InvalidArgumentException("Flag for option $printableOption overlaps with flag for option $printableOtherOption in property $this->name");
					}
				}

				throw new AssumptionFailedError("Unreachable");
			}

			$this->maxValue |= $possibleFlag;
		}
	}

	public function getName() : string{ return $this->name; }

	public function deserialize(object $block, BlockStateReader $in) : void{
		$flags = $in->readBoundedInt($this->name, 0, $this->maxValue);

		$value = [];
		foreach($this->map->getRawToValueMap() as $possibleFlag => $option){
			if(($flags & $possibleFlag) === $possibleFlag){
				$value[] = $option;
			}
		}

		($this->setter)($block, $value);
	}

	public function serialize(object $block, BlockStateWriter $out) : void{
		$flags = 0;

		$value = ($this->getter)($block);
		foreach($value as $option){
			$flags |= $this->map->valueToRaw($option);
		}

		$out->writeInt($this->name, $flags);
	}
}
