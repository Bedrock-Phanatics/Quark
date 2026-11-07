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

namespace quark\event;

use function mb_strtoupper;

/**
 * List of event priorities
 *
 * Events will be called in this order, lowest value first:
 * EARLIEST -> EARLY -> NORMAL -> LATE -> LATEST -> MONITOR
 *
 * MONITOR events should not change the event outcome or contents
 *
 * WARNING: Handler sorting in HandlerList::getListenerList() relies on these values increasing in call order.
 */
final class EventPriority{

	private function __construct(){
		//NOOP
	}

	public const ALL = [
		self::EARLIEST,
		self::EARLY,
		self::NORMAL,
		self::LATE,
		self::LATEST,
		self::MONITOR
	];

	/**
	 * Event call is ran first, to allow other plugins to further customise the outcome
	 */
	public const EARLIEST = 0;
	/**
	 * Event call is ran early
	 */
	public const EARLY = 1;
	/**
	 * Event call is neither early nor late, and may be ran normally.
	 * This is the default priority.
	 */
	public const NORMAL = 2;
	/**
	 * Event call is ran late
	 */
	public const LATE = 3;
	/**
	 * Event call is ran last before monitors and has the final say in what happens
	 * to the event
	 */
	public const LATEST = 4;
	/**
	 * Event is listened to purely for monitoring the outcome of an event.
	 *
	 * No modifications to the event should be made under this priority
	 */
	public const MONITOR = 5;

	/**
	 * @throws \InvalidArgumentException
	 */
	public static function fromString(string $name) : int{
		$value = [
			"EARLIEST" => self::EARLIEST,
			"EARLY" => self::EARLY,
			"NORMAL" => self::NORMAL,
			"LATE" => self::LATE,
			"LATEST" => self::LATEST,
			"MONITOR" => self::MONITOR
		][mb_strtoupper($name)] ?? null;
		if($value !== null){
			return $value;
		}

		throw new \InvalidArgumentException("Unable to resolve priority \"$name\"");
	}
}
