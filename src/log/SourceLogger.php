<?php

declare(strict_types=1);

namespace quark\log;

use quark\utils\MainLogger;
use function is_string;

class SourceLogger extends \PrefixedLogger{
	private ?string $forwardedSource = null;
	public function __construct(private \Logger $delegate, string $source){ parent::__construct($delegate, $source); }

	public function log($level, $message){
		if(!is_string($level)){ throw new \InvalidArgumentException("Log level must be a string"); }
		$source = $this->forwardedSource ?? $this->getPrefix();
		$this->forwardedSource = null;
		$this->logWithSource($level, $message, $source);
	}

	protected function logWithSource(string $level, string $message, string $source) : void{
		if($this->delegate instanceof self){
			$previous = $this->delegate->forwardedSource;
			$this->delegate->forwardedSource = $this->delegate->getPrefix() . " / " . $source;
			try{ $this->delegate->log($level, $message); }finally{ $this->delegate->forwardedSource = $previous; }
		}elseif($this->delegate instanceof MainLogger){
			$this->delegate->event($level, $message, $source);
		}else{ $this->delegate->log($level, "[$source] $message"); }
	}

	public function logException(\Throwable $e, $trace = null){
		$source = $this->forwardedSource ?? $this->getPrefix();
		$this->forwardedSource = null;
		$this->logExceptionWithSource($e, $trace, $source);
	}

	/** @param list<array<string, mixed>>|null $trace */
	protected function logExceptionWithSource(\Throwable $e, ?array $trace, string $source) : void{
		if($this->delegate instanceof self){
			$previous = $this->delegate->forwardedSource;
			$this->delegate->forwardedSource = $this->delegate->getPrefix() . " / " . $source;
			try{ $this->delegate->logException($e, $trace); }finally{ $this->delegate->forwardedSource = $previous; }
		}elseif($this->delegate instanceof MainLogger){
			$this->delegate->logException($e, $trace, $source);
		}else{ parent::logException($e, $trace); }
	}
}
