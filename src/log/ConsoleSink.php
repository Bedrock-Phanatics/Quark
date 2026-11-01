<?php

declare(strict_types=1);

namespace quark\log;

use function base64_encode;
use function fclose;
use function feof;
use function fopen;
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
use function usleep;
use const PHP_BINARY;

final class ConsoleSink implements Sink{
	/** @var resource|null */
	private $process = null;
	/** @var resource|null */
	private $socket = null;
	private string $pending = "";
	private int $dropped = 0;
	private int $totalDropped = 0;

	public function __construct(private Formatter $formatter){
		$stdout = fopen("php://stdout", "wb");
		$stderr = fopen("php://stderr", "wb");
		if($stdout === false || $stderr === false){
			if($stdout !== false){ fclose($stdout); }
			if($stderr !== false){ fclose($stderr); }
			throw new \RuntimeException("Cannot open console streams");
		}
		try{
			$process = @proc_open([PHP_BINARY, "-dopcache.enable_cli=0", "-r", 'require base64_decode($argv[1], true);', base64_encode(__DIR__ . "/console-output.php")], [0 => ["socket"], 1 => $stdout, 2 => $stderr], $pipes);
		}finally{ fclose($stdout); fclose($stderr); }
		if($process === false){ throw new \RuntimeException("Cannot start console output process"); }
		$this->process = $process;
		$this->socket = $pipes[0];
		if(!stream_set_blocking($this->socket, false)){ $this->close(); throw new \RuntimeException("Cannot make console transport nonblocking"); }
	}

	public function write(array $event) : bool{
		if(Level::priority($event["level"]) <= 3 || $event["category"] === "diagnostic"){
			$this->pump();
			if($this->pending !== ""){ ++$this->dropped; ++$this->totalDropped; return false; }
		}
		$line = $this->formatter->format($event);
		if(strlen($this->pending) + strlen($line) > 4194304){ ++$this->dropped; ++$this->totalDropped; return false; }
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
				$this->dropped = 0;
			}
		}
		$this->pending .= $line;
		return true;
	}

	private function pump() : void{
		if($this->pending === "" || $this->socket === null){ return; }
		$limit = min(strlen($this->pending), 262144);
		$offset = 0;
		for($attempt = 0; $offset < $limit && $attempt < 8; ++$attempt){
			$written = @fwrite($this->socket, substr($this->pending, $offset, min(65536, $limit - $offset)));
			if($written === false || feof($this->socket)){ throw new \RuntimeException("Console output process disconnected"); }
			if($written === 0){ break; }
			$offset += $written;
		}
		if($offset > 0){ $this->pending = substr($this->pending, $offset); }
	}

	public function flush() : void{ $this->pump(); }

	public function close() : void{
		$deadline = hrtime(true) + 500000000;
		try{
			while($this->pending !== "" && hrtime(true) < $deadline){
				$this->pump();
				if($this->pending !== ""){ usleep(1000); }
			}
		}finally{
			if($this->socket !== null){ fclose($this->socket); $this->socket = null; }
			if($this->process !== null){
				while(proc_get_status($this->process)["running"] && hrtime(true) < $deadline){ usleep(1000); }
				if(proc_get_status($this->process)["running"]){ proc_terminate($this->process); }
				proc_close($this->process);
				$this->process = null;
			}
		}
	}
	public function getDroppedCount() : int{ return $this->totalDropped; }

	public function __destruct(){
		if($this->process !== null){ try{ $this->close(); }catch(\Throwable){} }
	}

}
