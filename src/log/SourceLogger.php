<?php

declare(strict_types=1);

namespace quark\log;

use quark\utils\MainLogger;
use function is_string;

class SourceLogger extends \PrefixedLogger{
	public function __construct(private \Logger $delegate, string $source){ parent::__construct($delegate, $source); }

	public function log($level, $message){
		if(!is_string($level)){ throw new \InvalidArgumentException("Log level must be a string"); }
		if($this->delegate instanceof MainLogger){
			$this->delegate->event($level, $message, $this->getPrefix());
		}else{ parent::log($level, $message); }
	}

	public function logException(\Throwable $e, $trace = null){
		if($this->delegate instanceof MainLogger){
			$this->delegate->logException($e, $trace, $this->getPrefix());
		}else{ parent::logException($e, $trace); }
	}
}
