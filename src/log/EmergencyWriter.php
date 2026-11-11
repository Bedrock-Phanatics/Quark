<?php

declare(strict_types=1);

namespace quark\log;

use function clearstatcache;
use function fclose;
use function file_exists;
use function flock;
use function fopen;
use function fstat;
use function fwrite;
use function getmypid;
use function is_file;
use function is_link;
use function json_encode;
use function strlen;
use function sys_get_temp_dir;
use function tempnam;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_PARTIAL_OUTPUT_ON_ERROR;
use const LOCK_EX;
use const LOCK_NB;

/**
 * @phpstan-import-type LogEvent from Event
 */
final class EmergencyWriter{
	private static ?string $fallbackFile = null;

	private static function append(string $file, string $line) : bool{
		clearstatcache();
		if(is_link($file) || file_exists($file) && !is_file($file)){ return false; }
		$stream = @fopen($file, "ab");
		if($stream === false){ return false; }
		try{
			$stat = fstat($stream);
			return $stat !== false && ($stat["mode"] & 0170000) === 0100000 && @flock($stream, LOCK_EX | LOCK_NB) && @fwrite($stream, $line) === strlen($line);
		}finally{ fclose($stream); }
	}

	/** @param LogEvent $event */
	public static function write(?string $file, array $event, string $reason) : void{
		$event = Event::cleanEvent($event);
		$event["logging_failure"] = Event::clean(Event::limit($reason, 4096));
		$line = json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
		if($file !== null && self::append($file, $line)){ return; }
		$stderr = @fopen("php://stderr", "wb");
		if($stderr !== false){
			try{
				$stat = fstat($stderr);
				// PHP cannot safely probe or make stderr pipes nonblocking on every supported platform
				if($stat !== false && ($stat["mode"] & 0170000) === 0100000){
					$output = "Logging fallback: " . $line;
					if(@fwrite($stderr, $output) === strlen($output)){ return; }
				}
			}finally{ fclose($stderr); }
		}
		if(self::$fallbackFile === null || !is_file(self::$fallbackFile) || is_link(self::$fallbackFile)){
			$file = @tempnam(sys_get_temp_dir(), "quark-emergency-" . getmypid() . "-");
			self::$fallbackFile = $file === false ? null : $file;
		}
		if(self::$fallbackFile !== null){ self::append(self::$fallbackFile, $line); }
	}
}
