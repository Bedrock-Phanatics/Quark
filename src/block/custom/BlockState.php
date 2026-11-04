<?php

declare(strict_types=1);

namespace quark\block\custom;

use quark\data\bedrock\block\BlockStateDeserializeException;
use quark\data\bedrock\block\convert\BlockStateReader;
use quark\data\bedrock\block\convert\BlockStateWriter;
use quark\data\bedrock\block\convert\property\Property;
use function array_search;
use function array_unique;
use function count;
use function is_bool;
use function is_int;
use function preg_match;
use function range;
use const SORT_REGULAR;

/**
 * A named CustomBlock state with a fixed list of values. Values are stored compactly as their index.
 * @phpstan-implements Property<CustomBlock>
 */
final class BlockState implements Property{
	public const int MAX_VALUES = 256;

	private readonly int $maxIndex;

	/** @param list<bool>|list<int>|list<string> $values */
	private function __construct(
		private readonly string $name,
		private readonly array $values,
		private readonly int $default,
		private readonly bool $itemState
	){
		self::validateName($name);
		$this->maxIndex = count($values) - 1;
		if(count($values) < 2 || count($values) > self::MAX_VALUES || count(array_unique($values, SORT_REGULAR)) !== count($values)){
			throw new \InvalidArgumentException("State '$name' must have 2 - " . self::MAX_VALUES . " distinct values");
		}
		foreach($values as $value){
			if(!is_bool($value) && !is_int($value) && preg_match('/^[a-z0-9_]{1,32}$/D', $value) !== 1){
				throw new \InvalidArgumentException("State '$name' string values must be 1 - 32 lowercase letters, digits or underscores");
			}
		}
	}

	/** @internal */
	public static function validateName(string $name) : void{
		if(preg_match('/^[a-z0-9_]{1,32}:[a-z0-9_]{1,32}$/D', $name) !== 1 || preg_match('/^minecraft:/', $name) === 1){
			throw new \InvalidArgumentException("Invalid block state name '$name', expected a non-minecraft namespace:name");
		}
	}

	public static function bool(string $name, bool $default = false) : self{
		return new self($name, [false, true], $default ? 1 : 0, false);
	}

	public static function int(string $name, int $min, int $max, ?int $default = null) : self{
		if($min > $max || $max - $min >= self::MAX_VALUES || $min < -0x80000000 || $max > 0x7fffffff){
			throw new \InvalidArgumentException("Invalid range $min - $max for state '$name'");
		}
		$default ??= $min;
		if($default < $min || $default > $max){
			throw new \InvalidArgumentException("Default $default is outside the range of state '$name'");
		}
		return new self($name, range($min, $max), $default - $min, false);
	}

	/** @param list<string> $values */
	public static function enum(string $name, array $values, ?string $default = null) : self{
		$index = $default === null ? 0 : array_search($default, $values, true);
		if($index === false){
			throw new \InvalidArgumentException("Default '$default' is not a value of state '$name'");
		}
		return new self($name, $values, $index, false);
	}

	/** Keeps this state on the block item, like a color variant, instead of resetting it on pick or drop. */
	public function persistsInItem() : self{
		return new self($this->name, $this->values, $this->default, true);
	}

	public function getName() : string{ return $this->name; }

	/** @return list<bool>|list<int>|list<string> */
	public function getValues() : array{ return $this->values; }

	public function getDefaultIndex() : int{ return $this->default; }

	/** @internal */
	public function getMaxIndex() : int{ return $this->maxIndex; }

	public function isItemState() : bool{ return $this->itemState; }

	/** @internal */
	public function indexOf(bool|int|string $value) : ?int{
		$index = array_search($value, $this->values, true);
		return $index === false ? null : $index;
	}

	public function deserialize(object $block, BlockStateReader $in) : void{
		$value = match(true){
			is_bool($this->values[0]) => $in->readBool($this->name),
			is_int($this->values[0]) => $in->readInt($this->name),
			default => $in->readString($this->name)
		};
		$index = $this->indexOf($value) ?? throw new BlockStateDeserializeException("Invalid value for state '$this->name'");
		$block->setStateIndex($this->name, $index);
	}

	public function serialize(object $block, BlockStateWriter $out) : void{
		$value = $this->values[$block->getStateIndex($this->name)];
		match(true){
			is_bool($value) => $out->writeBool($this->name, $value),
			is_int($value) => $out->writeInt($this->name, $value),
			default => $out->writeString($this->name, $value)
		};
	}
}
