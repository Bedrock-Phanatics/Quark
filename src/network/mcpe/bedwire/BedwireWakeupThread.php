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

namespace quark\network\mcpe\bedwire;

use Bedwire\Server as BedwireServer;
use pocketmine\snooze\SleeperHandlerEntry;
use quark\thread\Thread;

/**
 * Wakes the main thread as soon as the Bedwire network thread publishes events, so packets are handled on arrival
 * instead of on the next tick. It sleeps in native code and exits when the server it waits on goes away.
 *
 * @internal
 */
final class BedwireWakeupThread extends Thread{

	public function __construct(
		private int $signal,
		private SleeperHandlerEntry $sleeperEntry
	){}

	protected function onRun() : void{
		$notifier = $this->sleeperEntry->createNotifier();
		$seen = 0;
		while(($sequence = BedwireServer::waitForEvents($this->signal, $seen, 1000)) !== null){
			if($sequence !== $seen){
				$seen = $sequence;
				$notifier->wakeupSleeper();
			}
		}
	}

	public function getThreadName() : string{
		return "Bedwire wakeup";
	}
}
