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
use quark\pulse\internal\PulseZones;
use quark\pulse\PulseZone;
use quark\utils\Utils;
use function hrtime;

/**
 * Custom Snooze sleeper handler which captures notification processing time.
 * @internal
 */
final class TimeTrackingSleeperHandler extends SleeperHandler{

	private int $notificationProcessingTimeNs = 0;

	public function __construct(
		private PulseZone $pulse
	){
		parent::__construct();
	}

	public function addNotifier(\Closure $handler) : SleeperHandlerEntry{
		$name = Utils::getNiceClosureName($handler);
		$pulse = PulseZones::dynamic("server.notifier." . $name);

		return parent::addNotifier(function() use ($pulse, $handler) : void{
			$scope = $pulse->start();
			try{
				$handler();
			}finally{
				$pulse->stop($scope);
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
		$scope = $this->pulse->start();
		try{
			parent::processNotifications();
		}finally{
			$this->notificationProcessingTimeNs += hrtime(true) - $startTime;
			$this->pulse->stop($scope);
		}
	}
}
