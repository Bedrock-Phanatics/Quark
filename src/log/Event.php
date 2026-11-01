<?php

declare(strict_types=1);

namespace quark\log;

use function array_slice;
use function count;
use function implode;
use function is_finite;
use function is_float;
use function is_int;
use function is_scalar;
use function is_string;
use function mb_scrub;
use function microtime;
use function preg_match;
use function preg_replace;
use function str_replace;
use function strlen;
use function substr;

/**
 * @phpstan-type ExceptionInfo array{type: string, message: string, file: string, line: int, trace: string}
 * @phpstan-type LogEvent array{time: float, level: string, source: string, thread: string, message: string, category: string, context: array<string, scalar|null>, exceptions: list<ExceptionInfo>, force?: bool}
 */
final class Event{
	public const MAX_MESSAGE_BYTES = 65536;

	public static function limit(string $value, int $bytes = self::MAX_MESSAGE_BYTES) : string{
		return strlen($value) <= $bytes ? $value : substr($value, 0, $bytes - 16) . " [truncated]";
	}

	/** @param array<string, scalar|null> $context
	 * @param list<ExceptionInfo> $exceptions
	 * @return LogEvent
	 */
	public static function create(string $level, string $message, string $source = "Server", string $thread = "Main", string $category = "log", array $context = [], array $exceptions = []) : array{
		Level::priority($level);
		if(count($context) > 32){ throw new \InvalidArgumentException("Log context is limited to 32 fields"); }
		if(count($exceptions) > 8){ throw new \InvalidArgumentException("Log exceptions are limited to 8 causes"); }
		$causes = [];
		foreach($exceptions as $exception){
			foreach(["type", "message", "file", "trace"] as $key){
				if(!isset($exception[$key]) || !is_string($exception[$key])){ throw new \InvalidArgumentException("Invalid log exception field"); }
				$exception[$key] = self::limit($exception[$key], $key === "trace" || $key === "message" ? 8192 : 1024);
			}
			if(!is_int($exception["line"] ?? null)){ throw new \InvalidArgumentException("Invalid log exception line"); }
			$causes[] = ["type" => $exception["type"], "message" => $exception["message"], "file" => $exception["file"], "line" => $exception["line"], "trace" => $exception["trace"]];
		}
		$fields = [];
		foreach(\quark\utils\Utils::promoteKeys($context) as $key => $value){
			if(!is_string($key) || strlen($key) > 64 || !(is_scalar($value) || $value === null) || is_float($value) && !is_finite($value)){
				throw new \InvalidArgumentException("Log context must contain short string keys and finite scalar values");
			}
			$fields[$key] = is_string($value) ? self::limit($value, 1024) : $value;
		}
		return ["time" => microtime(true), "level" => $level, "source" => self::limit($source, 128), "thread" => self::limit($thread, 128), "message" => self::limit($message), "category" => self::limit($category, 64), "context" => $fields, "exceptions" => $causes];
	}

	/** @param list<array<string, mixed>>|null $trace
	 * @return list<ExceptionInfo>
	 */
	public static function exceptions(\Throwable $error, ?array $trace = null) : array{
		$result = [];
		do{
			$result[] = ["type" => $error::class, "message" => self::limit($error->getMessage(), 8192), "file" => self::limit($error->getFile(), 1024), "line" => $error->getLine(), "trace" => self::limit($trace === null ? $error->getTraceAsString() : implode("\n", \quark\utils\Utils::printableTrace(array_slice($trace, 0, 128))), 8192)];
			$trace = null;
			$error = $error->getPrevious();
		}while($error !== null && count($result) < 8);
		return $result;
	}

	public static function clean(string $value) : string{
		if(preg_match('/[^\x20-\x7e\n]/', $value) === 0){ return $value; }
		$value = mb_scrub($value, "UTF-8");
		$value = str_replace(["\r\n", "\r", "\t"], ["\n", "\n", "    "], $value);
		return preg_replace('/\x1b\][^\x07\x1b]*(?:\x07|\x1b\\\\)|\x1b\[[0-?]*[ -\/]*[@-~]|\x{00A7}[0-9a-w]|[\x00-\x09\x0b-\x1f\x7f]|[\x{E000}-\x{F8FF}]/u', "", $value) ?? "[invalid log text]";
	}

	/** @param LogEvent $event
	 * @return LogEvent
	 */
	public static function cleanEvent(array $event) : array{
		foreach(["source", "thread", "message", "category"] as $key){ $event[$key] = self::clean($event[$key]); }
		$event["source"] = str_replace("\n", " ", $event["source"]);
		$event["thread"] = str_replace("\n", " ", $event["thread"]);
		foreach(\quark\utils\Utils::promoteKeys($event["context"]) as $key => $value){
			unset($event["context"][$key]);
			$event["context"][self::clean((string) $key)] = is_string($value) ? self::clean($value) : $value;
		}
		foreach($event["exceptions"] as $i => $exception){
			foreach(["type", "message", "file", "trace"] as $key){ $event["exceptions"][$i][$key] = self::clean($exception[$key]); }
		}
		return $event;
	}
}
