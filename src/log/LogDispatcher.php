<?php

declare(strict_types=1);

namespace quark\log;

use pmmp\thread\Thread;
use function dirname;
use function hrtime;
use function serialize;
use function unserialize;

/**
 * @phpstan-import-type LogEvent from Event
 */
final class LogDispatcher extends Thread{
	private EventQueue $queue;
	private string $configuration;

	/** @param array<string, mixed> $configuration */
	public function __construct(private ?string $file, private ?string $archive, int $maxSize = 33554432, int $retention = 0, array $configuration = [], private bool $console = false, private bool $ansi = false, private string $timezone = "UTC"){
		$configuration += ["max-file-size" => $maxSize, "max-archives" => $retention];
		$config = new LoggingConfig($configuration);
		$this->configuration = serialize($configuration);
		$this->queue = new EventQueue($config->bufferBytes);
		new \DateTimeZone($timezone);
	}

	public function getQueue() : EventQueue{ return $this->queue; }

	/** @param LogEvent $event */
	public function submit(array $event, bool $important) : void{
		if(!$this->queue->push(serialize($event), $important) && $important){
			EmergencyWriter::write($this->file === null ? null : $this->file . ".emergency", $event, "Event queue unavailable or full");
		}
	}

	/** @param list<array{string, bool}> $records */
	public function submitBatch(array $records) : void{
		foreach($this->queue->pushBatch($records) as $record){
			/** @var LogEvent $event */
			$event = unserialize($record, ["allowed_classes" => false]);
			EmergencyWriter::write($this->file === null ? null : $this->file . ".emergency", $event, "Event queue unavailable or full");
		}
	}

	public function syncFlushBuffer() : void{ $this->queue->flush(); }

	public function shutdown() : void{
		if(Thread::getCurrentThreadId() !== $this->getCreatorId()){
			throw new \LogicException("Only the creator can stop the log dispatcher");
		}
		if($this->isJoined()){ return; }
		$this->queue->close();
		try{ $this->queue->awaitFinish(); }finally{
			if(!$this->isRunning()){ $this->join(); }
		}
		if(!$this->isJoined()){ $this->join(); }
	}

	public function run() : void{
		require dirname(__DIR__, 2) . "/vendor/autoload.php";
		/** @var array<string, mixed> $configuration */
		$configuration = unserialize($this->configuration, ["allowed_classes" => false]);
		$config = new LoggingConfig($configuration);
		/** @var array<string, Sink> $sinks */
		$sinks = [];
		$thresholds = [];
		$emergency = $this->file === null ? null : $this->file . ".emergency";
		$failure = null;
		$fileFailed = false;
		$reportFailure = function(string $name, \Throwable $error) use (&$sinks, &$fileFailed, $emergency) : bool{
			if($name !== "console"){
				$fileFailed = true;
				$this->queue->reportSinkFailure($error->getMessage());
			}
			unset($sinks[$name]);
			EmergencyWriter::write($emergency, Event::create("error", "$name sink failed: " . $error->getMessage(), "Logger"), "Output sink failure");
			return $sinks === [];
		};
		try{
			if($this->file !== null){
				foreach(["file" => new TextFormatter($this->timezone), "json" => new JsonFormatter($this->timezone)] as $name => $formatter){
					if($name === "json" && !$config->json){ continue; }
					try{
						$sinks[$name] = new FileSink($this->file . ($name === "json" ? ".jsonl" : ""), $this->archive, $config->maxFileSize, $config->maxArchives, $formatter);
						$thresholds[$name] = $name === "json" ? $config->jsonLevel : $config->fileLevel;
					}catch(\Throwable $error){ $reportFailure($name, $error); }
				}
			}
			if($this->console){
				try{
					$sinks["console"] = new ConsoleSink(new TextFormatter($this->timezone, true, $this->ansi, $config->consoleStyle === "compact"));
					$thresholds["console"] = $config->consoleLevel;
				}catch(\Throwable $error){ $reportFailure("console", $error); }
			}
			$dispatch = function(string $record) use (&$sinks, &$fileFailed, $thresholds, $reportFailure, $emergency) : void{
				/** @var LogEvent $event */
				$event = unserialize($record, ["allowed_classes" => false]);
				$event = Event::cleanEvent($event);
				if($sinks === []){
					EmergencyWriter::write($emergency, $event, "No output sink available");
					return;
				}
				$priority = Level::priority($event["level"]);
				$important = $priority <= 3 || $event["category"] === "diagnostic";
				$handled = $fileFailed && $important;
				if($handled){ EmergencyWriter::write($emergency, $event, "Persistent sink unavailable"); }
				foreach(\quark\utils\Utils::promoteKeys($sinks) as $name => $sink){
					$name = (string) $name;
					if($event["category"] === "diagnostic" && $name === "console"){ continue; }
					$consoleOutput = $name === "console" && ($event["category"] === "output" || $event["category"] === "command");
					if(!$consoleOutput && $event["category"] !== "diagnostic" && $priority > $thresholds[$name] && !($priority === 7 && ($event["force"] ?? false))){ continue; }
					try{
						if($sink->write($event)){
							if($important){ $sink->flush(); }
							$handled = true;
						}
					}catch(\Throwable $error){
						$noSinks = $reportFailure($name, $error);
						if($important || $noSinks){
							EmergencyWriter::write($emergency, $event, "Sink write failed");
							$handled = true;
						}
					}
				}
				if($important && !$handled){ EmergencyWriter::write($emergency, $event, "No sink accepted important event"); }
			};
			$flush = function() use (&$sinks, $reportFailure) : void{
				foreach(\quark\utils\Utils::promoteKeys($sinks) as $name => $sink){
					$name = (string) $name;
					try{ $sink->flush(); }catch(\Throwable $error){ $reportFailure($name, $error); }
				}
			};
			$processed = 0;
			$acknowledged = 0;
			$reportedDrops = 0;
			$reportedConsoleDrops = 0;
			$lastConsoleReport = hrtime(true);
			$lastFlush = hrtime(true);
			do{
				$batch = $this->queue->take($config->flushMs * 1000);
				foreach($batch as $sequence => $record){
					$dispatch($record);
					$processed = $sequence;
				}
				$drops = $this->queue->stats()["dropped"];
				if($drops !== $reportedDrops){
					$dispatch(serialize(Event::create("warning", "Dropped " . ($drops - $reportedDrops) . " low-priority log events because the queue was full", "Logger")));
					$reportedDrops = $drops;
				}
				if(isset($sinks["console"]) && $sinks["console"] instanceof ConsoleSink){
					$consoleDrops = $sinks["console"]->getDroppedCount();
					$this->queue->setConsoleDropped($consoleDrops);
					if($consoleDrops > $reportedConsoleDrops && (hrtime(true) - $lastConsoleReport >= 1000000000 || $this->queue->drained())){
						$report = Event::create("warning", "Console dropped " . ($consoleDrops - $reportedConsoleDrops) . " output events while blocked", "Logger");
						foreach(["file", "json"] as $name){
							if(isset($sinks[$name])){ try{ $sinks[$name]->write($report); }catch(\Throwable $error){ $reportFailure($name, $error); } }
						}
						$reportedConsoleDrops = $consoleDrops;
						$lastConsoleReport = hrtime(true);
					}
				}
				[$ticket, $target] = $this->queue->flushRequest();
				$barrier = $ticket > $acknowledged && $processed >= $target;
				if($barrier || hrtime(true) - $lastFlush >= $config->flushMs * 1000000 || $this->queue->drained()){
					$flush();
					$lastFlush = hrtime(true);
					if($barrier){ $this->queue->acknowledge($ticket); $acknowledged = $ticket; }
				}else{
					if(isset($sinks["console"])){ try{ $sinks["console"]->flush(); }catch(\Throwable $error){ $reportFailure("console", $error); } }
				}
			}while(!$this->queue->drained());
		}catch(\Throwable $error){
			$failure = $error->getMessage();
			EmergencyWriter::write($emergency, Event::create("critical", $failure, "Logger"), "Dispatcher failure");
		}finally{
			foreach(\quark\utils\Utils::promoteKeys($sinks) as $name => $sink){
				$name = (string) $name;
				try{ $sink->close(); }catch(\Throwable $error){ $reportFailure($name, $error); }
			}
			$this->queue->finish($failure);
		}
	}
}
