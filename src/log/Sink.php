<?php

declare(strict_types=1);

namespace quark\log;

/**
 * @phpstan-import-type LogEvent from Event
 */
interface Sink{
	/** @param LogEvent $event */
	public function write(array $event) : void;
	public function flush() : void;
	public function close() : void;
}
