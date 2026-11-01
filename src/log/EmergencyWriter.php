<?php

declare(strict_types=1);

namespace quark\log;

use function error_log;
use function file_put_contents;
use function is_link;
use function json_encode;
use function strlen;
use const FILE_APPEND;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PARTIAL_OUTPUT_ON_ERROR;
use const LOCK_EX;

/**
 * @phpstan-import-type LogEvent from Event
 */
final class EmergencyWriter{
	/** @param LogEvent $event */
	public static function write(?string $file, array $event, string $reason) : void{
		$event = Event::cleanEvent($event);
		$event["logging_failure"] = Event::clean(Event::limit($reason, 4096));
		$line = json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
		if($file !== null && !is_link($file) && @file_put_contents($file, $line, FILE_APPEND | LOCK_EX) === strlen($line)){ return; }
		@error_log("Logging fallback: " . $line, 4);
	}
}
