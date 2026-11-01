<?php

declare(strict_types=1);

namespace quark\log;

use quark\utils\MainLogger;
use function is_string;

class SourceLogger extends \PrefixedLogger{
	public function __construct(private \Logger $delegate, string $source){ parent::__construct($delegate, $source); }

	public function log($level, $message){
		if(!is_string($level)){ throw new \InvalidArgumentException("Log level must be a string"); }
		$this->logWithSource($level, $message, $this->getPrefix());
	}

	protected function logWithSource(string $level, string $message, string $source) : void{
		if($this->delegate instanceof self){
			$this->delegate->logWithSource($level, $message, $this->delegate->getPrefix() . " / " . $source);
		}elseif($this->delegate instanceof MainLogger){
			$this->delegate->event($level, $message, $source);
		}else{ $this->delegate->log($level, "[$source] $message"); }
	}

	public function logException(\Throwable $e, $trace = null){
		$this->logExceptionWithSource($e, $trace, $this->getPrefix());
	}

	/** @param list<array<string, mixed>>|null $trace */
	protected function logExceptionWithSource(\Throwable $e, ?array $trace, string $source) : void{
		if($this->delegate instanceof self){
			$this->delegate->logExceptionWithSource($e, $trace, $this->delegate->getPrefix() . " / " . $source);
		}elseif($this->delegate instanceof MainLogger){
			$this->delegate->logException($e, $trace, $source);
		}else{ parent::logException($e, $trace); }
	}
}
