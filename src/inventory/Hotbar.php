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

namespace quark\inventory;

use quark\utils\ObjectSet;

/**
 * Tracks which of the first slots of a human's inventory is held in the main hand.
 */
final class Hotbar{
	public const SIZE = 9;

	private int $selectedIndex = 0;
	/**
	 * @var \Closure[]|ObjectSet
	 * @phpstan-var ObjectSet<\Closure(int $oldIndex) : void>
	 */
	private ObjectSet $selectedIndexChangeListeners;

	public function __construct(){
		$this->selectedIndexChangeListeners = new ObjectSet();
	}

	public function isHotbarSlot(int $slot) : bool{
		return $slot >= 0 && $slot < self::SIZE;
	}

	/**
	 * Returns the hotbar slot number currently held in the main hand.
	 */
	public function getSelectedIndex() : int{
		return $this->selectedIndex;
	}

	/**
	 * Sets which hotbar slot is held in the main hand. This doesn't fire any events, use Player::selectHotbarSlot()
	 * for that.
	 *
	 * @throws \InvalidArgumentException if the hotbar slot is out of range
	 */
	public function setSelectedIndex(int $hotbarSlot) : void{
		if(!$this->isHotbarSlot($hotbarSlot)){
			throw new \InvalidArgumentException("$hotbarSlot is not a valid hotbar slot index (expected 0 - " . (self::SIZE - 1) . ")");
		}
		$oldIndex = $this->selectedIndex;
		$this->selectedIndex = $hotbarSlot;
		foreach($this->selectedIndexChangeListeners as $callback){
			$callback($oldIndex);
		}
	}

	/**
	 * @return \Closure[]|ObjectSet
	 * @phpstan-return ObjectSet<\Closure(int $oldIndex) : void>
	 */
	public function getSelectedIndexChangeListeners() : ObjectSet{ return $this->selectedIndexChangeListeners; }

	public function getSize() : int{
		return self::SIZE;
	}
}
