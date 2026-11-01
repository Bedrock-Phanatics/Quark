<?php

declare(strict_types=1);

namespace quark\log;

final class Level{
	public const PRIORITIES = ["emergency" => 0, "alert" => 1, "critical" => 2, "error" => 3, "warning" => 4, "notice" => 5, "info" => 6, "debug" => 7];
	public const LABELS = ["EMERG", "ALERT", "CRIT", "ERROR", "WARN", "NOTE", "INFO", "DEBUG"];

	public static function priority(string $level) : int{
		return self::PRIORITIES[$level] ?? throw new \InvalidArgumentException("Unknown log level: $level");
	}
}
