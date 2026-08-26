<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

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
	 * @param TBlock                $block
	 * @param Closure(TBlock) : int $computer
	 */
	public static function register(Block $block, Closure $computer) : void{
		self::$computers[$block->getTypeId()] = static function(Block $input) use ($computer) : int{
			/** @var TBlock $input */
			return $computer($input);
		};
	}

	/**
	 * @return (Closure(Block) : int)|null $computer
	 */
	public static function get(Block $block) : ?Closure{
		return self::$computers[$block->getTypeId()] ?? null;
	}

	public static function getValue(Block $block) : int{
		return isset(self::$computers[$id = $block->getTypeId()]) ? (self::$computers[$id])($block) : 0;
	}
}
