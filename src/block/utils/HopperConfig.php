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

namespace quark\block\utils;

final class HopperConfig{
	public const MAX_TRANSFER_COOLDOWN = 1200;
	public const MAX_ITEMS_PER_TRANSFER = 64;

	private static bool $enabled = true;
	private static int $transferCooldown = 8;
	private static int $itemsPerTransfer = 1;
	private static int $maxUpdatesPerTick = 512;

	private static int $budgetTick = -1;
	private static int $budgetUsed = 0;

	public static function configure(bool $enabled, int $transferCooldown, int $itemsPerTransfer, int $maxUpdatesPerTick) : void{
		//a zero cooldown would let a ring of hoppers pass items around forever within one tick
		if($transferCooldown < 1 || $transferCooldown > self::MAX_TRANSFER_COOLDOWN){
			throw new \InvalidArgumentException("Hopper transfer cooldown must be from 1 to " . self::MAX_TRANSFER_COOLDOWN . ", got $transferCooldown");
		}
		if($itemsPerTransfer < 1 || $itemsPerTransfer > self::MAX_ITEMS_PER_TRANSFER){
			throw new \InvalidArgumentException("Hopper items per transfer must be from 1 to " . self::MAX_ITEMS_PER_TRANSFER . ", got $itemsPerTransfer");
		}
		if($maxUpdatesPerTick < 0){
			throw new \InvalidArgumentException("Hopper max updates per tick must not be negative, got $maxUpdatesPerTick");
		}
		self::$enabled = $enabled;
		self::$transferCooldown = $transferCooldown;
		self::$itemsPerTransfer = $itemsPerTransfer;
		self::$maxUpdatesPerTick = $maxUpdatesPerTick;
		self::$budgetTick = -1;
		self::$budgetUsed = 0;
	}

	public static function isEnabled() : bool{ return self::$enabled; }
	public static function getTransferCooldown() : int{ return self::$transferCooldown; }
	public static function getItemsPerTransfer() : int{ return self::$itemsPerTransfer; }
	/** Zero means unlimited. */
	public static function getMaxUpdatesPerTick() : int{ return self::$maxUpdatesPerTick; }

	/**
	 * Claims one hopper update from the server-wide budget for the given tick.
	 */
	public static function tryClaimUpdate(int $tick) : bool{
		if(self::$maxUpdatesPerTick === 0){
			return true;
		}
		if($tick !== self::$budgetTick){
			self::$budgetTick = $tick;
			self::$budgetUsed = 0;
		}
		if(self::$budgetUsed >= self::$maxUpdatesPerTick){
			return false;
		}
		++self::$budgetUsed;
		return true;
	}
}
