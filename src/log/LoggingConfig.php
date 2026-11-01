<?php

declare(strict_types=1);

namespace quark\log;

use function array_key_exists;
use function get_debug_type;
use function implode;
use function in_array;
use function is_string;

final readonly class LoggingConfig{
	public int $consoleLevel;
	public int $fileLevel;
	public int $jsonLevel;
	public string $color;
	public string $consoleStyle;
	public bool $json;
	public int $bufferBytes;
	public int $flushMs;
	public int $maxFileSize;
	public int $maxArchives;

	/** @param array<array-key, mixed> $values */
	public function __construct(array $values = []){
		$defaults = ["console-level" => "info", "file-level" => "info", "json-level" => "info", "color" => "auto", "console-style" => "columns", "json" => false, "buffer-bytes" => 8388608, "flush-ms" => 200, "max-file-size" => 33554432, "max-archives" => 0];
		foreach(\quark\utils\Utils::promoteKeys($values) as $key => $value){
			if(!is_string($key) || !array_key_exists($key, $defaults) || get_debug_type($value) !== get_debug_type($defaults[$key])){
				throw new \InvalidArgumentException("Invalid logging.$key: unknown setting or incorrect type");
			}
		}
		/** @var array{console-level: string, file-level: string, json-level: string, color: string, console-style: string, json: bool, buffer-bytes: int, flush-ms: int, max-file-size: int, max-archives: int} $values */
		$values = $values + $defaults;
		foreach(["console-level", "file-level", "json-level"] as $key){
			if(!isset(Level::PRIORITIES[$values[$key]])){
				throw new \InvalidArgumentException("Invalid logging.$key: expected emergency, alert, critical, error, warning, notice, info, or debug");
			}
		}
		foreach(["color" => ["auto", "always", "never"], "console-style" => ["columns", "compact"]] as $key => $allowed){
			if(!in_array($values[$key], $allowed, true)){
				throw new \InvalidArgumentException("Invalid logging.$key: expected " . implode(", ", $allowed));
			}
		}
		foreach(["buffer-bytes" => [262144, 67108864], "flush-ms" => [1, 5000], "max-file-size" => [1024, 1099511627776], "max-archives" => [0, 100000]] as $key => [$min, $max]){
			if($values[$key] < $min || $values[$key] > $max){
				throw new \InvalidArgumentException("Invalid logging.$key: expected integer from $min to $max");
			}
		}
		$this->consoleLevel = Level::priority($values["console-level"]);
		$this->fileLevel = Level::priority($values["file-level"]);
		$this->jsonLevel = Level::priority($values["json-level"]);
		$this->color = $values["color"];
		$this->consoleStyle = $values["console-style"];
		$this->json = $values["json"];
		$this->bufferBytes = $values["buffer-bytes"];
		$this->flushMs = $values["flush-ms"];
		$this->maxFileSize = $values["max-file-size"];
		$this->maxArchives = $values["max-archives"];
	}
}
