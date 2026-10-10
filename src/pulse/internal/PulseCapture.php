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

namespace quark\pulse\internal;

use pmmp\thread\ThreadSafe;
use quark\pulse\PulseReport;
use function count;
use function igbinary_serialize;
use function igbinary_unserialize;
use function strlen;

/**
 * @internal Immutable capture shared between workers.
 * @phpstan-import-type Capture from PulseContext
 */
final class PulseCapture extends ThreadSafe{
	public const MAX_TRANSFER_BYTES = PulseReport::MAX_BYTES * 4;
	private string $data;
	private int $rows;
	private int $bytes;

	/** @param Capture $capture */
	public function __construct(array $capture){
		$this->rows = count($capture["nodes"]) + count($capture["ticks"]) + count($capture["spikes"]);
		foreach($capture["spikes"] as $spike){ $this->rows += count($spike["nodes"]); }
		if(isset($capture["network"])){
			$this->rows += count($capture["network"]["sessions"]) + count($capture["network"]["events"]) + count($capture["network"]["windows"]);
		}
		if($this->rows > PulseReport::MAX_ROWS){ throw new \LengthException("Pulse capture exceeds the row budget"); }
		$this->data = igbinary_serialize($capture) ?? throw new \InvalidArgumentException("Pulse capture must be serializable");
		$this->bytes = strlen($this->data);
		// Binary array headers need more space than the final JSON.
		if($this->bytes > self::MAX_TRANSFER_BYTES){ throw new \LengthException("Pulse capture transfer exceeds the size limit"); }
	}

	public function getRowCount() : int{ return $this->rows; }
	public function getByteSize() : int{ return $this->bytes; }

	/** @return Capture */
	public function decode() : array{
		/** @var Capture $capture */
		$capture = igbinary_unserialize($this->data);
		return $capture;
	}
}
