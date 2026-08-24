<?php

declare(strict_types=1);

namespace pocketmine\block\utils\redstone;

interface Waitable{

	/**
	 * Used as a callback method when {@see RedstoneWorldState::scheduleWaitableUpdate()}
	 * gets called.
	 */
	public function onRedstoneTickReceive() : void;
}