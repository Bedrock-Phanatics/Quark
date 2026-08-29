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

namespace quark\network\mcpe;

use quark\inventory\Inventory;

final class ComplexInventoryMapEntry{

	/**
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $reverseSlotMap = [];

	/**
	 * @param int[] $slotMap
	 * @phpstan-param array<int, int> $slotMap
	 */
	public function __construct(
		private Inventory $inventory,
		private array $slotMap
	){
		foreach($slotMap as $slot => $index){
			$this->reverseSlotMap[$index] = $slot;
		}
	}

	public function getInventory() : Inventory{ return $this->inventory; }

	/**
	 * @return int[]
	 * @phpstan-return array<int, int>
	 */
	public function getSlotMap() : array{ return $this->slotMap; }

	public function mapNetToCore(int $slot) : ?int{
		return $this->slotMap[$slot] ?? null;
	}

	public function mapCoreToNet(int $slot) : ?int{
		return $this->reverseSlotMap[$slot] ?? null;
	}
}
