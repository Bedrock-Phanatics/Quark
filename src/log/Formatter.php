<?php

declare(strict_types=1);

namespace quark\log;

/**
 * @phpstan-import-type LogEvent from Event
 */
interface Formatter{
	/** @param LogEvent $event */
	public function format(array $event) : string;
}
