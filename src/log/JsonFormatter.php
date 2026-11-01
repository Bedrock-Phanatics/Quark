<?php

declare(strict_types=1);

namespace quark\log;

use function json_encode;
use function min;
use function sprintf;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

final class JsonFormatter implements Formatter{
	private \DateTimeZone $timezone;
	private int $second = -1;
	private string $time = "";
	private string $offset = "";

	public function __construct(string $timezone){ $this->timezone = new \DateTimeZone($timezone); }

	public function format(array $event) : string{
		$second = (int) $event["time"];
		if($this->second !== $second){
			$this->second = $second;
			$date = (new \DateTimeImmutable("@$second"))->setTimezone($this->timezone);
			$this->time = $date->format("Y-m-d\\TH:i:s");
			$this->offset = $date->format("P");
		}
		$event["timestamp"] = $this->time . sprintf(".%03d", min(999, (int) (($event["time"] - $second) * 1000))) . $this->offset;
		return json_encode($event, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
	}
}
