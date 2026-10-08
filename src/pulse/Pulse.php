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

namespace quark\pulse;

use quark\pulse\internal\PulseContext;

final class Pulse{
	// pmmpthread statics are thread-local.
	private static ?PulseContext $context = null;
	private static ?PulseSession $session = null;

	public static function zone(string $name) : PulseZone{
		return (self::$context ??= new PulseContext())->zone($name);
	}

	public static function start(string $threadName = "main") : PulseSession{
		$session = new PulseSession(self::$context ??= new PulseContext(), $threadName);
		return self::$session = $session;
	}

	public static function stop() : void{
		self::$session?->stop();
	}

	public static function isRecording() : bool{
		return self::$context->recording ?? false;
	}

	public static function getSession() : ?PulseSession{
		return self::$session;
	}
}
