<?php

declare(strict_types=1);

namespace pocketmine\block\tile\comparator;

use Closure;
use pocketmine\block\Block;
use pocketmine\block\Redstone;
use pocketmine\block\RedstoneComparator;
use pocketmine\block\RedstoneRepeater;
use pocketmine\block\RedstoneWire;
use pocketmine\block\VanillaBlocks;

final class ComparatorWeightRegistry{

	/** @var array<int, Closure(Block) : int> */
	private static array $computers = [];

	public static function init() : void{
		$block = VanillaBlocks::REDSTONE_WIRE();
		self::register($block, static function(RedstoneWire $block) : int{ return $block->getPowerLevel(); });

		$block = VanillaBlocks::REDSTONE();
		self::register($block, static function(Redstone $block) : int{ return $block->getPowerLevel(); });

		$block = VanillaBlocks::REDSTONE_REPEATER();
		self::register($block, static function(RedstoneRepeater $block) : int{ return $block->getPowerLevel(); });

		$block = VanillaBlocks::REDSTONE_COMPARATOR();
		self::register($block, static function(RedstoneComparator $block) : int{ return $block->getPowerLevel(); });
	}

	/**
	 * @template TBlock of Block
	 * @param TBlock $block
	 * @param Closure(TBlock) : int $computer
	 */
	public static function register(Block $block, Closure $computer) : void{
		self::$computers[$block->getTypeId()] = static function(Block $input) use ($computer) : int{
			/** @var TBlock $input */
			return $computer($input);
		};
	}

	/**
	 * @param Block $block
	 * @return (Closure(Block) : int)|null $computer
	 */
	public static function get(Block $block) : ?Closure{
		return self::$computers[$block->getTypeId()] ?? null;
	}

	public static function getValue(Block $block) : int{
		return isset(self::$computers[$id = $block->getTypeId()]) ? (self::$computers[$id])($block) : 0;
	}
}