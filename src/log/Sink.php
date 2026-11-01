<?php

declare(strict_types=1);

namespace quark\log;

/**
 * @phpstan-import-type LogEvent from Event
 */
interface Sink{
	/** @param LogEvent $event
	 * @return bool whether the sink accepted the event
	 */
	public function write(array $event) : bool;
	public function flush() : void;
	public function close() : void;
}
