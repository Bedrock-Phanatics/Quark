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

namespace quark\event\world;

use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\world\Weather;
use quark\world\World;

/**
 * Called when a world's weather is about to change, either naturally or through World::setWeather().
 * Cancelling a natural change keeps the current weather for another random duration.
 */
final class WorldWeatherChangeEvent extends WorldEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		World $world,
		private Weather $oldWeather,
		private Weather $newWeather,
		private int $duration
	){
		parent::__construct($world);
	}

	public function getOldWeather() : Weather{ return $this->oldWeather; }

	public function getNewWeather() : Weather{ return $this->newWeather; }

	/**
	 * Returns how many ticks the new weather will last for.
	 */
	public function getDuration() : int{ return $this->duration; }
}
