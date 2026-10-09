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

use quark\pulse\PulseReport;

/**
 * @internal Runs on Pulse's dedicated export pool.
 */
final class PulseReportWriteTask extends AsyncTask{
	private string $json;
	private ?string $error = null;

	/** @param \Closure(?string, ?string) : void $onComplete */
	public function __construct(PulseReport $report, private string $directory, \Closure $onComplete){
		$this->json = $report->encode();
		$this->storeLocal("complete", $onComplete);
	}

	public function onRun() : void{
		try{
			$this->setResult(PulseReport::writeEncoded($this->directory, $this->json));
		}catch(\RuntimeException|\JsonException|\LengthException $e){
			$this->error = $e->getMessage();
		}finally{
			$this->json = "";
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
