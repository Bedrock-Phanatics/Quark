<?php

declare(strict_types=1);

namespace quark\log;

use pmmp\thread\ThreadSafe;
use pmmp\thread\ThreadSafeArray;
use function count;
use function hrtime;
use function intdiv;
use function max;
use function min;
use function strlen;

final class EventQueue extends ThreadSafe{
	/** @var ThreadSafeArray<int, string> */
	private ThreadSafeArray $records;
	private int $bytes = 0;
	private int $accepted = 0;
	private int $taken = 0;
	private int $dropped = 0;
	private int $fallback = 0;
	private int $consoleDropped = 0;
	private int $flushTicket = 0;
	private int $flushTarget = 0;
	private int $flushedTicket = 0;
	private int $activeFlushTicket = 0;
	private int $activeFlushTarget = 0;
	private bool $closed = false;
	private bool $finished = false;
	private ?string $failure = null;
	private ?string $sinkFailure = null;

	public function __construct(private int $capacity = 8388608){
		if($capacity < 262144 || $capacity > 67108864){ throw new \InvalidArgumentException("Invalid logging buffer capacity"); }
		$this->records = new ThreadSafeArray();
	}

	public function push(string $record, bool $important) : bool{
		return $this->synchronized(function() use ($record, $important) : bool{
			$limit = $important ? $this->capacity : $this->capacity - intdiv($this->capacity, 4);
			$countLimit = $important ? 8192 : 6144;
			if($this->closed || $this->failure !== null || $this->bytes + strlen($record) > $limit || count($this->records) >= $countLimit){
				if($important){ ++$this->fallback; }else{ ++$this->dropped; }
				return false;
			}
			$empty = count($this->records) === 0;
			$this->records[++$this->accepted] = $record;
			$this->bytes += strlen($record);
			if($empty){ $this->notify(); }
			return true;
		});
	}

	/** @param list<array{string, bool}> $records
	 * @return list<string>
	 */
	public function pushBatch(array $records) : array{
		return $this->synchronized(function() use ($records) : array{
			$failed = [];
			foreach($records as [$record, $important]){
				if(!$this->push($record, $important) && $important){ $failed[] = $record; }
			}
			return $failed;
		});
	}

	/** @return array<int, string> */
	public function take(int $waitUs) : array{
		return $this->synchronized(function() use ($waitUs) : array{
			if(count($this->records) === 0 && !$this->closed && $this->flushTicket === $this->flushedTicket){ $this->wait($waitUs); }
			$batch = [];
			$bytes = 0;
			while(($record = $this->records->shift()) !== null){
				$batch[++$this->taken] = $record;
				$length = strlen($record);
				$this->bytes -= $length;
				$bytes += $length;
				if(count($batch) >= 64 || $bytes >= 262144){ break; }
			}
			return $batch;
		});
	}

	/** @return array{int, int} */
	public function flushRequest() : array{
		return $this->synchronized(function() : array{
			if($this->activeFlushTicket <= $this->flushedTicket){
				$this->activeFlushTicket = $this->flushTicket;
				$this->activeFlushTarget = $this->flushTarget;
			}
			return [$this->activeFlushTicket, $this->activeFlushTarget];
		});
	}

	public function acknowledge(int $ticket) : void{
		$this->synchronized(function() use ($ticket) : void{
			$this->flushedTicket = $ticket;
			$this->notify();
		});
	}

	public function flush(float $timeout = 5.0) : void{
		$this->synchronized(function() use ($timeout) : void{
			$ticket = ++$this->flushTicket;
			$this->flushTarget = $this->accepted;
			$this->notify();
			$deadline = hrtime(true) + (int) ($timeout * 1e9);
			while($this->flushedTicket < $ticket && !$this->finished && $this->failure === null){
				$remaining = $deadline - hrtime(true);
				if($remaining <= 0){ throw new \RuntimeException("Log flush timed out"); }
				$this->wait(max(1, (int) min(100000, $remaining / 1000)));
			}
			if(($failure = $this->failure ?? $this->sinkFailure) !== null){ throw new \RuntimeException("Log writer failed: $failure"); }
			if($this->flushedTicket < $ticket){ throw new \RuntimeException("Log writer is shut down"); }
		});
	}

	public function reportSinkFailure(string $failure) : void{
		$this->synchronized(function() use ($failure) : void{ $this->sinkFailure ??= $failure; $this->notify(); });
	}

	public function setConsoleDropped(int $dropped) : void{ $this->consoleDropped = $dropped; }

	public function close() : void{
		$this->synchronized(function() : void{ $this->closed = true; $this->notify(); });
	}

	public function drained() : bool{
		return $this->synchronized(fn() => $this->closed && count($this->records) === 0);
	}

	public function finish(?string $failure) : void{
		$this->synchronized(function() use ($failure) : void{
			$this->failure = $failure;
			if($failure === null && $this->sinkFailure === null){ $this->flushedTicket = $this->flushTicket; }
			$this->finished = true;
			$this->closed = true;
			$this->notify();
		});
	}

	public function awaitFinish(float $timeout = 5.0) : void{
		$this->synchronized(function() use ($timeout) : void{
			$deadline = hrtime(true) + (int) ($timeout * 1e9);
			while(!$this->finished){
				if(hrtime(true) >= $deadline){ throw new \RuntimeException("Log shutdown timed out"); }
				$this->wait(100000);
			}
			if(($failure = $this->failure ?? $this->sinkFailure) !== null){ throw new \RuntimeException("Log writer failed: $failure"); }
		});
	}

	/** @return array{queued: int, bytes: int, accepted: int, dropped: int, fallback: int, console_dropped: int} */
	public function stats() : array{
		return $this->synchronized(fn() => ["queued" => count($this->records), "bytes" => $this->bytes, "accepted" => $this->accepted, "dropped" => $this->dropped, "fallback" => $this->fallback, "console_dropped" => $this->consoleDropped]);
	}
}
