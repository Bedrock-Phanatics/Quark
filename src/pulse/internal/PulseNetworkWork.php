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

use function array_pop;
use function chr;
use function count;
use function hrtime;
use function max;
use function ord;
use function pack;
use function strlen;
use const PHP_INT_MIN;

/**
 * @internal Main-thread state; only the packed record crosses threads.
 */
final class PulseNetworkWork{
	public const MAX_DEPTH = 32;
	public const PREFIX_BYTES = 49;
	public const BATCH_DECODE = 0;
	public const DECRYPT = 1;
	public const DECOMPRESS = 2;
	public const PACKET_STATE = 3;
	public const DECODE_EVENT = 4;
	public const DECODE = 5;
	public const RECEIVE_EVENT = 6;
	public const HANDLE = 7;
	public const NESTED_LIMIT = 8;
	public const STAGES = ["batch_decode", "decrypt", "decompress", "packet_state", "decode_event", "decode", "receive_event", "handle", "nested_limit"];
	/** @var array<int, string> */
	private array $frames = [];
	private int $overflow = 0;
	private int $sequence = 0;
	private bool $recording = true;

	public function __construct(private readonly int $started, private readonly PulseNetworkWatchdog $watchdog){}

	public function enter(int $session, ?int $protocol, string $phase, ?int $packet, ?int $tick, int $stage) : int{
		if(!$this->recording){ return 0; }
		if(count($this->frames) === self::MAX_DEPTH){
			if($this->overflow++ === 0){ $this->watchdog->record = $this->pack(0, null, "unknown", null, $tick, self::NESTED_LIMIT); }
			return 0;
		}
		$this->frames[] = $this->pack($session, $protocol, $phase, $packet, $tick, $stage);
		$this->watchdog->record = $this->frames[count($this->frames) - 1];
		return count($this->frames);
	}

	private function pack(int $session, ?int $protocol, string $phase, ?int $packet, ?int $tick, int $stage) : string{
		$now = (int) hrtime(true);
		return pack("J5N2C", ++$this->sequence, $now, max(0, $now - $this->started), $tick ?? 0, $protocol ?? PHP_INT_MIN, $session, $packet ?? 1024, strlen($phase)) . $phase . chr($stage);
	}

	public function stage(int $token, int $stage) : void{
		if(!$this->recording || $token < 1 || $this->overflow > 0 || $token !== count($this->frames)){ return; }
		$frame = $this->frames[$token - 1];
		if(ord($frame[-1]) === $stage){ return; }
		$frame[-1] = chr($stage);
		$this->watchdog->record = $this->frames[$token - 1] = $frame;
	}

	public function leave(int $token) : void{
		if(!$this->recording){ return; }
		if($token === 0){
			if($this->overflow === 0 || --$this->overflow > 0){ return; }
		}elseif($token === count($this->frames)){
			array_pop($this->frames);
		}else{ return; }
		$this->watchdog->record = $this->frames === [] ? "" : $this->frames[count($this->frames) - 1];
	}

	public function stop() : void{
		$this->recording = false;
		$this->frames = [];
		$this->overflow = 0;
		$this->watchdog->record = "";
	}
}
