<?php

declare(strict_types=1);

namespace quark\log;

use function base64_encode;
use function count;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function fwrite;
use function hrtime;
use function min;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function stream_set_blocking;
use function strlen;
use function substr;
use function unpack;
use function usleep;
use const PHP_BINARY;
use const PHP_OS_FAMILY;

/**
 * @phpstan-import-type LogEvent from Event
 */
final class ConsoleSink implements Sink{
	/** @var resource|null */
	private $process = null;
	/** @var resource|null */
	private $socket = null;
	// Replay unacknowledged bytes after a disconnect
	// A crash between output and acknowledgement can repeat the last fragment
	private string $pending = "";
	private int $sent = 0;
	private string $acknowledgements = "";
	private bool $recovered = false;
	private bool $closed = false;
	private int $queuedBytes = 0;
	private int $confirmedBytes = 0;
	/** @var array<int, LogEvent|null> */
	private array $events = [];
	private int $dropped = 0;
	private int $totalDropped = 0;

	public function __construct(private Formatter $formatter, private ?string $emergencyFile = null, private bool $stderr = false){
		$this->recovered = $stderr;
		$this->start();
	}

	private function start() : void{
		$stdout = @fopen("php://stdout", "wb");
		$stderr = @fopen("php://stderr", "wb");
		$output = $this->stderr && $stderr !== false ? $stderr : $stdout;
		if($output === false){ $output = $stderr; }
		if($output === false){
			throw new \RuntimeException("Cannot open console streams");
		}
		try{
			$process = @proc_open([PHP_BINARY, "-n", "-r", 'require base64_decode("' . base64_encode(__DIR__ . "/console-output.php") . '", true);'], [0 => ["socket"], 1 => $output, 2 => $stderr === false ? $output : $stderr], $pipes);
		}finally{
			if($stdout !== false){ fclose($stdout); }
			if($stderr !== false){ fclose($stderr); }
		}
		if($process === false){ throw new \RuntimeException("Cannot start console output process"); }
		$this->process = $process;
		$this->socket = $pipes[0];
		if(!stream_set_blocking($this->socket, false)){ $this->stop(); throw new \RuntimeException("Cannot make console transport nonblocking"); }
	}

	public function write(array $event) : bool{
		if($this->closed){ return false; }
		$important = Level::priority($event["level"]) <= 3 || $event["category"] === "diagnostic";
		if($important){
			$this->pump();
			if($this->sent < strlen($this->pending)){ ++$this->dropped; ++$this->totalDropped; return false; }
		}
		$line = $this->formatter->format($event);
		if(strlen($this->pending) + strlen($line) > 4194304 || count($this->events) >= 8192){ ++$this->dropped; ++$this->totalDropped; return false; }
		if($this->dropped > 0){
			$report = $event;
			$report["level"] = "warning";
			$report["source"] = "Logger";
			$report["category"] = "log";
			$report["message"] = "Console dropped $this->dropped events while output was blocked";
			$report["exceptions"] = [];
			$report["context"] = [];
			$notice = $this->formatter->format($report);
			if(strlen($this->pending) + strlen($notice) + strlen($line) <= 4194304){
				$this->pending .= $notice;
				$this->queuedBytes += strlen($notice);
				$this->dropped = 0;
			}
		}
		$this->pending .= $line;
		$this->queuedBytes += strlen($line);
		$this->events[$this->queuedBytes] = $important || $event["category"] === "output" || $event["category"] === "command" ? $event : null;
		return true;
	}

	private function pump() : void{
		if($this->pending === ""){ return; }
		while(true){
			try{
				if($this->socket === null){ $this->start(); }
				$this->transfer();
				return;
			}catch(\RuntimeException $error){
				$this->stop();
				if($this->stderr){ throw $error; }
				$this->stderr = $this->recovered;
				$this->recovered = true;
				$this->sent = 0;
				$this->acknowledgements = "";
			}
		}
	}

	private function acknowledge() : void{
		$socket = $this->socket ?? throw new \LogicException("Console transport is closed");
		$data = @fread($socket, 65536);
		if($data === false){ throw new \RuntimeException("Console output process disconnected"); }
		$this->acknowledgements .= $data;
		$offset = 0;
		$acknowledged = 0;
		while(strlen($this->acknowledgements) - $offset >= 4){
			/** @var array{bytes: int} $acknowledgement */
			$acknowledgement = unpack("Nbytes", $this->acknowledgements, $offset);
			$acknowledged += $acknowledgement["bytes"];
			$offset += 4;
		}
		if($acknowledged > $this->sent){ throw new \RuntimeException("Invalid console output acknowledgement"); }
		if($acknowledged > 0){
			$this->pending = substr($this->pending, $acknowledged);
			$this->sent -= $acknowledged;
			$this->confirmedBytes += $acknowledged;
			foreach(\quark\utils\Utils::promoteKeys($this->events) as $end => $_){
				if($end > $this->confirmedBytes){ break; }
				unset($this->events[$end]);
			}
		}
		$this->acknowledgements = substr($this->acknowledgements, $offset);
		if($this->pending !== "" && (feof($socket) || PHP_OS_FAMILY === "Windows" && $data === "" && $this->process !== null && !proc_get_status($this->process)["running"])){
			throw new \RuntimeException("Console output process disconnected");
		}
	}

	private function transfer() : void{
		$socket = $this->socket ?? throw new \LogicException("Console transport is closed");
		$this->acknowledge();
		$limit = min(strlen($this->pending), $this->sent + 262144);
		for($attempt = 0; $this->sent < $limit && $attempt < 8; ++$attempt){
			$written = @fwrite($socket, substr($this->pending, $this->sent, min(65536, $limit - $this->sent)));
			if($written === false || feof($socket)){ throw new \RuntimeException("Console output process disconnected"); }
			if($written === 0){ break; }
			$this->sent += $written;
		}
		$this->acknowledge();
	}

	public function flush() : void{ if(!$this->closed){ $this->pump(); } }

	public function close() : void{
		if($this->closed){ return; }
		$this->closed = true;
		$deadline = hrtime(true) + 500000000;
		try{
			while($this->pending !== "" && $this->socket !== null && hrtime(true) < $deadline){
				$this->pump();
				if($this->pending !== ""){ usleep(1000); }
			}
		}finally{
			$this->stop($deadline);
			if($this->events !== []){
				$this->totalDropped += count($this->events);
				foreach($this->events as $event){
					if($event !== null){ EmergencyWriter::write($this->emergencyFile, $event, "Console output was not acknowledged before transport closed"); }
				}
				EmergencyWriter::write($this->emergencyFile, Event::create("warning", "Console abandoned " . count($this->events) . " unacknowledged events when closing", "Logger"), "Console output did not drain");
				$this->events = [];
				$this->pending = "";
				$this->sent = 0;
			}
		}
	}

	private function stop(int $deadline = 0) : void{
		if($this->socket !== null){ fclose($this->socket); $this->socket = null; }
		if($this->process !== null){
			while(proc_get_status($this->process)["running"] && hrtime(true) < $deadline){ usleep(1000); }
			if(proc_get_status($this->process)["running"]){ proc_terminate($this->process, 9); }
			proc_close($this->process);
			$this->process = null;
		}
	}
	public function getDroppedCount() : int{ return $this->totalDropped; }

	public function __destruct(){
		if($this->process !== null || $this->events !== []){ try{ $this->close(); }catch(\Throwable){} }
	}

}
