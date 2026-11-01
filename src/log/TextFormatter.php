<?php

declare(strict_types=1);

namespace quark\log;

use function count;
use function explode;
use function implode;
use function json_encode;
use function max;
use function mb_strimwidth;
use function mb_strwidth;
use function str_pad;
use function str_repeat;
use function str_replace;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * @phpstan-import-type LogEvent from Event
 */
final class TextFormatter implements Formatter{
	private int $second = -1;
	private string $time = "";
	private \DateTimeZone $timezone;

	public function __construct(string $timezone, private bool $console = false, private bool $ansi = false, private bool $compact = false){
		$this->timezone = new \DateTimeZone($timezone);
	}

	public function format(array $event) : string{
		$second = (int) $event["time"];
		if($second !== $this->second){
			$this->second = $second;
			$this->time = (new \DateTimeImmutable("@$second"))->setTimezone($this->timezone)->format($this->console ? "H:i:s" : "Y-m-d\\TH:i:sP");
		}
		$priority = Level::priority($event["level"]);
		$label = match($event["category"]){ "output" => "OUT", "command" => "CMD", default => Level::LABELS[$priority] };
		$source = $event["source"];
		if($this->console && !$this->compact){
			$source = mb_strimwidth($source, 0, 16, "…", "UTF-8");
			$source .= str_repeat(" ", max(0, 16 - mb_strwidth($source, "UTF-8")));
		}
		$level = str_pad($label, 5);
		$prefix = $this->time . "  " . $level . "  " . $source . ($this->console ? "  " : " [" . $event["thread"] . "]  ");
		$indent = str_repeat(" ", mb_strwidth($prefix, "UTF-8"));
		if($this->ansi){
			$color = match(true){ $priority <= 3 => "31", $priority === 4 => "33", $priority === 7 => "90", default => "36" };
			$prefix = "\x1b[90m" . $this->time . "\x1b[0m  \x1b[" . $color . "m" . $level . "\x1b[0m  " . $source . "  ";
		}
		$message = $event["message"];
		foreach($event["exceptions"] as $i => $exception){
			$message .= "\n" . ($i === 0 ? "" : "Caused by ") . $exception["type"] . ": " . $exception["message"] . " at " . $exception["file"] . ":" . $exception["line"] . "\n  " . str_replace("\n", "\n  ", $exception["trace"]);
		}
		if($event["context"] !== []){ $message .= " " . json_encode($event["context"], JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
		$lines = explode("\n", $message, 1025);
		if(count($lines) === 1025){ $lines[1024] = "[additional lines truncated]"; }
		return $prefix . implode("\n" . $indent, $lines) . "\n";
	}
}
