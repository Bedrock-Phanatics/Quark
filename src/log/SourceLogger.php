<?php

declare(strict_types=1);

namespace quark\log;

use quark\utils\MainLogger;
use function is_string;

class SourceLogger extends \PrefixedLogger{
	public function __construct(private \Logger $delegate, string $source){ parent::__construct($delegate, $source); }

	public function log($level, $message, ?string $source = null){
		if(!is_string($level)){ throw new \InvalidArgumentException("Log level must be a string"); }
		$source = $this->getPrefix() . ($source === null ? "" : " / " . $source);
		if($this->delegate instanceof self){
			$this->delegate->log($level, $message, $source);
		}elseif($this->delegate instanceof MainLogger){
			$this->delegate->event($level, $message, $source);
		}else{ $this->delegate->log($level, "[$source] $message"); }
	}

	public function logException(\Throwable $e, $trace = null, ?string $source = null){
		$source = $this->getPrefix() . ($source === null ? "" : " / " . $source);
		if($this->delegate instanceof self || $this->delegate instanceof MainLogger){
			$this->delegate->logException($e, $trace, $source);
		}else{ parent::logException($e, $trace); }
	}
}
