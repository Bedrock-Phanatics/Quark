<?php

declare(strict_types=1);

namespace quark\log;

use quark\utils\Filesystem;
use function clearstatcache;
use function fclose;
use function fflush;
use function file_exists;
use function fopen;
use function fstat;
use function fwrite;
use function gmdate;
use function is_dir;
use function is_file;
use function is_link;
use function mkdir;
use function pathinfo;
use function rename;
use function strlen;
use function substr;
use function touch;
use const PATHINFO_EXTENSION;
use const PATHINFO_FILENAME;

final class FileSink implements Sink{
	/** @var resource|null */
	private $stream = null;
	private int $size = 0;
	private string $buffer = "";
	private int $archiveIndex = 0;

	public function __construct(private string $file, private ?string $archive, private int $maxSize, private int $retention, private Formatter $formatter){
		self::prepare($file, $archive);
		$this->open();
		if($archive !== null && $this->size >= $maxSize){ $this->rotate(); }
	}

	public static function prepare(string $file, ?string $archive) : void{
		if(is_link($file) || file_exists($file) && !is_file($file) || !@touch($file)){
			throw new \RuntimeException("Unable to create log file: $file");
		}
		if($archive !== null && (is_link($archive) || !is_dir($archive) && !@mkdir($archive))){
			throw new \RuntimeException("Unable to create archive directory: $archive");
		}
	}

	private function open() : void{
		if(is_link($this->file)){ throw new \RuntimeException("Refusing log symlink: $this->file"); }
		$stream = @fopen($this->file, "ab");
		if($stream === false){ throw new \RuntimeException("Cannot open log file: $this->file"); }
		$stat = fstat($stream);
		if($stat === false){ fclose($stream); throw new \RuntimeException("Cannot stat log file: $this->file"); }
		$this->stream = $stream;
		$this->size = $stat["size"];
	}

	/** @param resource $stream */
	public static function writeAll($stream, string $data) : void{
		$length = strlen($data);
		for($offset = 0; $offset < $length; $offset += $written){
			$written = @fwrite($stream, $offset === 0 ? $data : substr($data, $offset));
			if($written === false || $written === 0){ throw new \RuntimeException("Cannot write log stream"); }
		}
	}

	private function drain() : void{
		if($this->buffer !== ""){
			if($this->stream === null){ throw new \RuntimeException("Log file is closed"); }
			self::writeAll($this->stream, $this->buffer);
			$this->buffer = "";
		}
	}

	private function rotate() : void{
		$this->close();
		$archive = $this->archive;
		if($archive === null){ return; }
		clearstatcache();
		if(is_link($archive) || !is_dir($archive) && !@mkdir($archive)){
			throw new \RuntimeException("Cannot create archive directory: $archive");
		}
		$base = pathinfo($this->file, PATHINFO_FILENAME);
		$extension = pathinfo($this->file, PATHINFO_EXTENSION);
		$stamp = gmdate("Y-m-d\\TH.i.s");
		do{
			$destination = $archive . "/$base.$stamp." . $this->archiveIndex++ . ".$extension";
		}while(file_exists($destination) || is_link($destination));
		if(is_link($this->file) || !@rename($this->file, $destination)){
			throw new \RuntimeException("Cannot rotate log file: $this->file");
		}
		$this->open();
		if($this->retention > 0){ Filesystem::retainNewestFiles($archive, "$base.", ".$extension", $this->retention, $destination); }
	}

	public function write(array $event) : bool{
		$line = $this->formatter->format($event);
		$length = strlen($line);
		if($this->archive !== null && $this->size > 0 && $this->size + $length > $this->maxSize){ $this->rotate(); }
		$this->buffer .= $line;
		$this->size += $length;
		if(strlen($this->buffer) >= 65536){ $this->drain(); }
		return true;
	}

	public function flush() : void{
		$this->drain();
		if($this->stream !== null && !@fflush($this->stream)){ throw new \RuntimeException("Cannot flush log file: $this->file"); }
	}

	public function close() : void{
		try{ $this->flush(); }finally{
			if($this->stream !== null){
				$stream = $this->stream;
				$this->stream = null;
				if(!@fclose($stream)){ throw new \RuntimeException("Cannot close log file: $this->file"); }
			}
		}
	}

	public function __destruct(){
		if($this->stream !== null){ @fclose($this->stream); }
	}
}
