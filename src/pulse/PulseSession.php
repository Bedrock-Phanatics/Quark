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

namespace quark\pulse;

use quark\pulse\internal\PulseContext;
use function hrtime;

/**
 * @phpstan-import-type Capture from PulseContext
 */
final class PulseSession{
	/** @var Capture|null */
	private ?array $capture = null;

	/** @internal */
	public function __construct(private readonly PulseContext $context, string $threadName, int $durationNs = 0, int $spikeThresholdNs = 0, int $maxSpikes = 32){
		$this->context->start($threadName, hrtime(true), $durationNs, $spikeThresholdNs, $maxSpikes);
	}

	public function isRecording() : bool{
		return $this->capture === null && $this->context->recording;
	}

	public function stop() : void{
		if($this->capture === null){
			$this->context->stop(hrtime(true));
			$this->capture = $this->context->capture();
		}
	}

	/** @return Capture */
	public function getCapture() : array{
		if(!$this->context->recording){
			$this->stop();
		}
		return $this->capture ?? $this->context->capture();
	}

	/** @param array<string, mixed> $metadata */
	public function getReport(array $metadata = []) : PulseReport{
		return PulseReport::create([$this->getCapture()], $metadata);
	}
}
