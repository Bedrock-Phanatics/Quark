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

use pmmp\thread\Thread;
use pmmp\thread\ThreadSafeArray;
use function clearstatcache;
use function count;
use function date;
use function fclose;
use function fflush;
use function file_exists;
use function file_put_contents;
use function fopen;
use function fstat;
use function fwrite;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function mkdir;
use function pathinfo;
use function rename;
use function stream_set_write_buffer;
use function strlen;
use function substr;
use function touch;
use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;

final class MainLoggerThread extends Thread{
	private const MAX_BUFFER_BYTES = 8 * 1024 * 1024;

	/** @phpstan-var ThreadSafeArray<int, string> */
	private ThreadSafeArray $buffer;
	private int $bufferBytes = 0;
	private int $droppedLines = 0;
	private ?string $failure = null;
	private bool $syncFlush = false;
	private bool $shutdown = false;
	private string $archiveTimestamp = "";
	private int $archiveIndex = 0;

	public function __construct(
		private string $logFile,
		private ?string $archiveDir,
		private readonly int $maxFileSize = 32 * 1024 * 1024,
		private readonly int $maxArchives = 0
	){
		if($maxFileSize < 1024 || $maxFileSize > 1_099_511_627_776 || $maxArchives < 0){
			throw new \InvalidArgumentException("Invalid log rotation limits");
		}
		$this->buffer = new ThreadSafeArray();
		if(is_link($this->logFile) || !@touch($this->logFile)){
			throw new \RuntimeException("Unable to create log file: $this->logFile");
		}
		if($this->archiveDir !== null && (is_link($this->archiveDir) || !@mkdir($this->archiveDir) && !is_dir($this->archiveDir))){
			throw new \RuntimeException("Unable to create archive directory: " . (
				is_file($this->archiveDir) ? "it already exists and is not a directory" : "permission denied"));
		}
	}

	public function write(string $line) : void{
		$bytes = strlen($line);
		if($bytes > self::MAX_BUFFER_BYTES){
			$line = substr($line, 0, self::MAX_BUFFER_BYTES - 32) . " [log line truncated]\n";
			$bytes = strlen($line);
		}
		$this->synchronized(function() use ($line) : void{
			if($this->failure !== null || $this->shutdown){
				return;
			}
			$bytes = strlen($line);
			while($this->bufferBytes + $bytes > self::MAX_BUFFER_BYTES){
				$old = $this->buffer->shift();
				if($old === null){ break; }
				$this->bufferBytes -= strlen($old);
				++$this->droppedLines;
			}
			$this->buffer[] = $line;
			$this->bufferBytes += $bytes;
			$this->notify();
		});
	}

	public function syncFlushBuffer() : void{
		$this->synchronized(function() : void{
			if($this->failure !== null){
				throw new \RuntimeException("Log writer failed: $this->failure");
			}
			if($this->shutdown){
				throw new \RuntimeException("Log writer is shut down");
			}
			$this->syncFlush = true;
			$this->notify(); //write immediately
		});
		$this->synchronized(function() : void{
			while($this->syncFlush && $this->failure === null){
				$this->wait(); //block until it's all been written to disk
			}
			if($this->failure !== null){
				throw new \RuntimeException("Log writer failed: $this->failure");
			}
		});
	}

	public function shutdown() : void{
		$this->synchronized(function() : void{
			$this->shutdown = true;
			$this->notify();
		});
		$this->join();
		if($this->failure !== null){
			throw new \RuntimeException("Log writer failed: $this->failure");
		}
	}

	/** @return resource */
	private function openLogFile(string $file, int &$size){
		$logResource = fopen($file, "ab");
		if(!is_resource($logResource)){
			throw new \RuntimeException("Couldn't open log file");
		}
		$stat = fstat($logResource);
		if($stat === false){
			throw new AssumptionFailedError("fstat() should not fail here");
		}
		$size = $stat['size'];
		stream_set_write_buffer($logResource, 64 * 1024);
		return $logResource;
	}

	/**
	 * @param resource $logResource
	 * @return resource
	 */
	private function archiveLogFile($logResource, int &$size, string $archiveDir){
		if(!fflush($logResource) || !fclose($logResource)){
			throw new \RuntimeException("Cannot flush log file before rotation: $this->logFile");
		}

		clearstatcache();

		$date = date("Y-m-d\TH.i.s");
		if($date !== $this->archiveTimestamp){
			$this->archiveTimestamp = $date;
			$this->archiveIndex = 0;
		}
		$baseName = pathinfo($this->logFile, PATHINFO_FILENAME);
		$extension = pathinfo($this->logFile, PATHINFO_EXTENSION);
		do{
			$fileName = "$baseName.$date." . $this->archiveIndex++ . ".$extension";
			$out = $this->archiveDir . "/" . $fileName;
		}while(file_exists($out) || is_link($out));

		//the user may have externally deleted the whole directory - make sure it exists before we do anything
		if(is_link($archiveDir) || (!is_dir($archiveDir) && !@mkdir($archiveDir)) || !@rename($this->logFile, $out)){
			throw new \RuntimeException("Cannot rotate log file into $archiveDir");
		}

		$logResource = $this->openLogFile($this->logFile, $size);
		$header = "--- Starting new log file - old log file archived as $fileName ---\n";
		if(fwrite($logResource, $header) !== strlen($header)){
			throw new \RuntimeException("Cannot write rotated log header");
		}
		$size += strlen($header);
		if($this->maxArchives > 0){
			require_once __DIR__ . "/Filesystem.php";
			Filesystem::retainNewestFiles($archiveDir, "$baseName.", ".$extension", $this->maxArchives, $out);
		}

		return $logResource;
	}

	private function logFileReadyToArchive(int $size) : bool{
		return $size >= $this->maxFileSize;
	}

	/** @param resource $logResource */
	private function writeLogLine(&$logResource, int &$size, ?string $archiveDir, string $line) : void{
		$length = strlen($line);
		if($archiveDir !== null && $size > 0 && $size + $length > $this->maxFileSize){
			$logResource = $this->archiveLogFile($logResource, $size, $archiveDir);
		}
		for($offset = 0; $offset < $length; $offset += $written){
			$written = fwrite($logResource, $offset === 0 ? $line : substr($line, $offset));
			if($written === false || $written === 0){
				throw new \RuntimeException("Cannot write log file: $this->logFile");
			}
		}
		$size += $length;
	}

	/** @param resource $logResource */
	private function writeLogStream(&$logResource, int &$size, ?string $archiveDir) : void{
		while(true){
			$chunk = null;
			$dropped = 0;
			$this->synchronized(function() use (&$chunk, &$dropped) : void{
				$chunk = $this->buffer->shift();
				if($chunk !== null){
					$this->bufferBytes -= strlen($chunk);
				}
				$dropped = $this->droppedLines;
				$this->droppedLines = 0;
			});
			if($dropped > 0){
				$this->writeLogLine($logResource, $size, $archiveDir, date("Y-m-d H:i:s") . " [Logger/WARNING]: dropped $dropped buffered log lines\n");
			}
			if($chunk === null){
				break;
			}
			$this->writeLogLine($logResource, $size, $archiveDir, $chunk);
		}

		if($this->syncFlush && !fflush($logResource)){
			throw new \RuntimeException("Cannot flush log file: $this->logFile");
		}
		$this->synchronized(function() : void{
			if($this->syncFlush && count($this->buffer) === 0 && $this->droppedLines === 0){
				$this->syncFlush = false;
				$this->notify();
			}
		});
	}

	public function run() : void{
		$logResource = null;
		try{
			$size = 0;
			$logResource = $this->openLogFile($this->logFile, $size);
			$archiveDir = $this->archiveDir;
			if($archiveDir !== null && $this->logFileReadyToArchive($size)){
				$logResource = $this->archiveLogFile($logResource, $size, $archiveDir);
			}
			while(true){
				$this->writeLogStream($logResource, $size, $archiveDir);
				$done = false;
				$this->synchronized(function() use (&$done) : void{
					$done = $this->shutdown && count($this->buffer) === 0;
					if(!$done && count($this->buffer) === 0 && !$this->syncFlush){
						$this->wait();
					}
				});
				if($done){
					break;
				}
			}
		}catch(\Throwable $e){
			$this->synchronized(function() use ($e) : void{
				$this->failure = $e->getMessage();
				$this->notify();
			});
			@file_put_contents("php://stderr", "[ERROR] Log writer failed: " . $e->getMessage() . "\n");
		}finally{
			if(is_resource($logResource)){
				fclose($logResource);
			}
		}
	}
}
