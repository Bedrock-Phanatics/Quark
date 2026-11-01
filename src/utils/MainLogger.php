<?php

/*
 *
 *   ___  _   _   _    ____  _  __
 *  / _ \| | | | / \  |  _ \| |/ /
 * | | | | | | |/ _ \ | |_) | ' /
 * | |_| | |_| / ___ \|  _ <| . \
 *  \__\_|\___/_/   \_\_| \_\_|\_\
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author Quark Team
 * @link https://github.com/Bedrock-Phanatics/Quark
 *
 *
 */

declare(strict_types=1);

namespace quark\utils;

use pmmp\thread\Thread as NativeThread;
use quark\log\EmergencyWriter;
use quark\log\Event;
use quark\log\Level;
use quark\log\LogDispatcher;
use quark\log\LoggingConfig;
use quark\log\TextFormatter;
use quark\thread\log\AttachableThreadSafeLogger;
use quark\thread\Thread;
use quark\thread\Worker;
use function count;
use function is_string;
use function max;
use function rtrim;
use function serialize;
use function strlen;
use function strrpos;
use function substr;

/**
 * @phpstan-import-type ExceptionInfo from Event
 */
class MainLogger extends AttachableThreadSafeLogger implements \BufferedLogger{
	private bool $logDebug;
	private bool $legacyDebug;
	private int $threshold;
	private ?LogDispatcher $dispatcher = null;
	private string $timezone;
	private ?string $emergencyFile;
	private static ?string $threadName = null;
	private static bool $dispatchingAttachments = false;
	private static ?self $bufferingLogger = null;
	/** @var list<array{string, bool}> */
	private static array $buffered = [];
	private static int $bufferedBytes = 0;

	/** @param array<string, mixed> $configuration */
	public function __construct(?string $logFile, bool $useFormattingCodes, private string $mainThreadName, \DateTimeZone $timezone, bool $logDebug = false, ?string $logArchiveDir = null, int $maxFileSize = 33554432, int $maxArchives = 0, array $configuration = []){
		parent::__construct();
		$config = new LoggingConfig($configuration);
		$this->threshold = max($config->consoleLevel, $logFile === null ? 0 : $config->fileLevel, $logFile !== null && $config->json ? $config->jsonLevel : 0);
		$this->legacyDebug = !isset($configuration["console-level"]) || $logFile !== null && (!isset($configuration["file-level"]) || $config->json && !isset($configuration["json-level"]));
		$this->timezone = $timezone->getName();
		$this->emergencyFile = $logFile === null ? null : $logFile . ".emergency";
		$this->dispatcher = new LogDispatcher($logFile, $logArchiveDir, $maxFileSize, $maxArchives, $configuration, true, $useFormattingCodes, $this->timezone);
		$this->setLogDebug($logDebug);
		if(!$this->getDispatcher()->start(NativeThread::INHERIT_NONE)){
			$this->dispatcher = null;
			throw new \RuntimeException("Cannot start log dispatcher");
		}
	}

	public function getLogWriterThread() : ?LogDispatcher{ return $this->emergencyFile === null ? null : $this->dispatcher; }

	public function getDispatcher() : LogDispatcher{ return $this->dispatcher ?? throw new \LogicException("Logger is not initialized"); }
	public function emergency($message){ $this->log("emergency", $message); }
	public function alert($message){ $this->log("alert", $message); }
	public function critical($message){ $this->log("critical", $message); }
	public function error($message){ $this->log("error", $message); }
	public function warning($message){ $this->log("warning", $message); }
	public function notice($message){ $this->log("notice", $message); }
	public function info($message){ $this->log("info", $message); }

	public function debug($message, bool $force = false){
		if($this->logDebug || $this->threshold === 7 || $force){ $this->event("debug", $message, force: $force); }
	}

	public function setLogDebug(bool $logDebug) : void{
		$this->logDebug = $logDebug && $this->legacyDebug;
		$this->getDispatcher()->debug = $this->logDebug;
	}

	public function log($level, $message){
		if(!is_string($level)){ throw new \InvalidArgumentException("Log level must be a string"); }
		if($level === "debug"){ $this->debug($message); }else{ $this->event($level, $message); }
	}

	/** @param array<string, scalar|null> $context
	 * @param list<ExceptionInfo> $exceptions
	 */
	public function event(string $level, string $message, string $source = "Server", array $context = [], string $category = "log", array $exceptions = [], bool $force = false) : void{
		$priority = Level::priority($level);
		if(!$force && $priority > $this->threshold && !($priority === 7 && $this->logDebug) && $category !== "output" && $category !== "command"){ return; }
		if(self::$threadName === null){
			$thread = NativeThread::getCurrentThread();
			self::$threadName = $thread === null ? $this->mainThreadName : (($thread instanceof Thread || $thread instanceof Worker) ? $thread->getThreadName() : substr($thread::class, strrpos($thread::class, "\\") === false ? 0 : strrpos($thread::class, "\\") + 1));
			self::$threadName .= "#" . NativeThread::getCurrentThreadId();
		}
		$event = Event::create($level, $message, $source, self::$threadName, $category, $context, $exceptions);
		if($force){ $event["force"] = true; }
		$important = $priority <= 3 || $category === "diagnostic";
		if(self::$bufferingLogger === $this){
			$record = serialize($event);
			self::$buffered[] = [$record, $important];
			self::$bufferedBytes += strlen($record);
			if(count(self::$buffered) >= 64 || self::$bufferedBytes >= 262144){ $this->flushBatch(); }
		}else{ $this->getDispatcher()->submit($event, $important); }
		if(count($this->attachments) > 0 && !self::$dispatchingAttachments){
			self::$dispatchingAttachments = true;
			try{
				$rendered = rtrim((new TextFormatter($this->timezone))->format(Event::cleanEvent($event)), "\n");
				foreach($this->getAttachments() as $attachment){
					try{ $attachment->log($level, $rendered); }catch(\Throwable $error){
						EmergencyWriter::write($this->emergencyFile, $event, "Logger attachment failed: " . $error->getMessage());
					}
				}
			}finally{ self::$dispatchingAttachments = false; }
		}
	}

	public function output(string $message) : void{ $this->event("info", $message, "Console", category: "output"); }

	/** @param list<array<string, mixed>>|null $trace */
	public function logException(\Throwable $e, $trace = null, string $source = "Server"){
		$exceptions = Event::exceptions($e, $trace);
		$this->event("critical", "Unhandled exception", $source, exceptions: $exceptions);
		try{ $this->syncFlushBuffer(); }catch(\Throwable $error){
			EmergencyWriter::write($this->emergencyFile, Event::create("critical", "Unhandled exception", $source, exceptions: $exceptions), "Log flush failed: " . $error->getMessage());
		}
	}

	private function flushBatch() : void{
		$records = self::$buffered;
		self::$buffered = [];
		self::$bufferedBytes = 0;
		$this->getDispatcher()->submitBatch($records);
	}

	public function buffer(\Closure $c) : void{
		if(self::$bufferingLogger !== null){ $c(); return; }
		self::$bufferingLogger = $this;
		try{ $c(); }finally{
			try{ $this->flushBatch(); }finally{ self::$bufferingLogger = null; }
		}
	}

	public function syncFlushBuffer() : void{
		if(self::$bufferingLogger === $this){ $this->flushBatch(); }
		$this->getDispatcher()->syncFlushBuffer();
	}

	public function shutdownLogWriterThread() : void{
		if(self::$bufferingLogger === $this){ $this->flushBatch(); }
		$this->getDispatcher()->shutdown();
	}

	public function __destruct(){
		if($this->dispatcher !== null && !$this->getDispatcher()->isJoined() && NativeThread::getCurrentThreadId() === $this->getDispatcher()->getCreatorId()){
			try{ $this->shutdownLogWriterThread(); }catch(\Throwable $error){
				EmergencyWriter::write($this->emergencyFile, Event::create("error", $error->getMessage(), "Logger"), "Shutdown failure");
			}
		}
	}
}
