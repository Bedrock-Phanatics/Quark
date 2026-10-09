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

use quark\pulse\internal\PulseContext;
use quark\pulse\PulseReport;
use function count;
use function igbinary_serialize;
use function igbinary_unserialize;
use function strlen;

/**
 * @internal Runs on Pulse's dedicated export pool.
 * @phpstan-import-type Capture from PulseContext
 */
final class PulseReportWriteTask extends AsyncTask{
	private string $data;
	private ?string $error = null;

	/**
	 * @param list<Capture>                     $captures
	 * @param array<string, mixed>              $metadata
	 * @param \Closure(?string, ?string) : void $onComplete
	 */
	public function __construct(array $captures, array $metadata, private string $directory, \Closure $onComplete){
		if(count($captures) > 128){ throw new \LengthException("Pulse report has too many threads"); }
		$rows = 0;
		foreach($captures as $capture){
			$rows += count($capture["nodes"]) + count($capture["ticks"]) + count($capture["spikes"]);
			foreach($capture["spikes"] as $spike){ $rows += count($spike["nodes"]); }
			if($rows > PulseReport::MAX_ROWS){ throw new \LengthException("Pulse report exceeds the row budget"); }
		}
		$this->data = igbinary_serialize([$captures, $metadata]) ?? throw new \InvalidArgumentException("Pulse captures must be serializable");
		// Binary array headers need more space than the final JSON.
		if(strlen($this->data) > PulseReport::MAX_BYTES * 4){ throw new \LengthException("Pulse capture transfer exceeds the size limit"); }
		$this->storeLocal("complete", $onComplete);
	}

	public function onRun() : void{
		try{
			/** @var array{list<Capture>, array<string, mixed>} $data */
			$data = igbinary_unserialize($this->data);
			$this->data = "";
			$this->setResult(PulseReport::create($data[0], $data[1])->write($this->directory));
		}catch(\RuntimeException|\InvalidArgumentException|\JsonException|\LengthException $e){
			$this->error = $e->getMessage();
		}finally{
			$this->data = "";
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
