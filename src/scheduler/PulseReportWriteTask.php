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

namespace quark\scheduler;

use pmmp\thread\ThreadSafeArray;
use quark\pulse\internal\PulseCapture;
use quark\pulse\PulseReport;
use function count;
use function igbinary_serialize;
use function igbinary_unserialize;
use function strlen;

/**
 * @internal Runs on Pulse's dedicated export pool.
 */
final class PulseReportWriteTask extends AsyncTask{
	/** @var ThreadSafeArray<int, PulseCapture>|null */
	private ?ThreadSafeArray $captures = null;
	private string $metadata;
	private ?string $error = null;

	/**
	 * @param list<PulseCapture>                $captures
	 * @param array<string, mixed>              $metadata
	 * @param \Closure(?string, ?string) : void $onComplete
	 */
	public function __construct(array $captures, array $metadata, private string $directory, \Closure $onComplete){
		if(count($captures) > 128){ throw new \LengthException("Pulse report has too many threads"); }
		$rows = $bytes = 0;
		foreach($captures as $capture){
			$rows += $capture->getRowCount();
			$bytes += $capture->getByteSize();
			if($rows > PulseReport::MAX_ROWS){ throw new \LengthException("Pulse report exceeds the row budget"); }
			if($bytes > PulseCapture::MAX_TRANSFER_BYTES){ throw new \LengthException("Pulse capture transfer exceeds the size limit"); }
		}
		$this->metadata = igbinary_serialize($metadata) ?? throw new \InvalidArgumentException("Pulse metadata must be serializable");
		if(strlen($this->metadata) > PulseCapture::MAX_TRANSFER_BYTES - $bytes){ throw new \LengthException("Pulse capture transfer exceeds the size limit"); }
		$this->captures = new ThreadSafeArray();
		foreach($captures as $capture){ $this->captures[] = $capture; }
		$this->storeLocal("complete", $onComplete);
	}

	public function onRun() : void{
		try{
			$captures = [];
			foreach($this->captures ?? [] as $capture){
				/** @var PulseCapture $capture */
				$captures[] = $capture->decode();
			}
			$this->captures = null;
			/** @var array<string, mixed> $metadata */
			$metadata = igbinary_unserialize($this->metadata);
			$this->metadata = "";
			$this->setResult(PulseReport::create($captures, $metadata)->write($this->directory));
		}catch(\RuntimeException|\InvalidArgumentException|\JsonException|\LengthException $e){
			$this->error = $e->getMessage();
		}finally{
			$this->captures = null;
			$this->metadata = "";
		}
	}

	public function onCompletion() : void{
		/** @var \Closure(?string, ?string) : void $complete */
		$complete = $this->fetchLocal("complete");
		/** @var string|null $file */
		$file = $this->getResult();
		$complete($file, $this->error);
	}
}
