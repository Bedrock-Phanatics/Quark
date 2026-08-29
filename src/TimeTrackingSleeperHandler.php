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

namespace quark;

use pocketmine\snooze\SleeperHandler;
use pocketmine\snooze\SleeperHandlerEntry;
use quark\timings\TimingsHandler;
use quark\utils\Utils;
use function hrtime;

/**
 * Custom Snooze sleeper handler which captures notification processing time.
 * @internal
 */
final class TimeTrackingSleeperHandler extends SleeperHandler{

	private int $notificationProcessingTimeNs = 0;

	/**
	 * @var TimingsHandler[]
	 * @phpstan-var array<string, TimingsHandler>
	 */
	private static array $handlerTimings = [];

	public function __construct(
		private TimingsHandler $timings
	){
		parent::__construct();
	}

	public function addNotifier(\Closure $handler) : SleeperHandlerEntry{
		$name = Utils::getNiceClosureName($handler);
		$timings = self::$handlerTimings[$name] ??= new TimingsHandler("Snooze Handler: " . $name, $this->timings);

		return parent::addNotifier(function() use ($timings, $handler) : void{
			$timings->startTiming();
			try{
				$handler();
			}finally{
				$timings->stopTiming();
			}
		});
	}

	/**
	 * Returns the time in nanoseconds spent processing notifications since the last reset.
	 */
	public function getNotificationProcessingTime() : int{ return $this->notificationProcessingTimeNs; }

	/**
	 * Resets the notification processing time tracker to zero.
	 */
	public function resetNotificationProcessingTime() : void{ $this->notificationProcessingTimeNs = 0; }

	public function processNotifications() : void{
		$startTime = hrtime(true);
		$this->timings->startTiming();
		try{
			parent::processNotifications();
		}finally{
			$this->notificationProcessingTimeNs += hrtime(true) - $startTime;
			$this->timings->stopTiming();
		}
	}
}
