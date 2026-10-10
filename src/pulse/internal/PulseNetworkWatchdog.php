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

use quark\thread\Thread;
use quark\utils\MainLoggerThread;
use function count;
use function date;
use function hrtime;
use function json_encode;
use function ord;
use function substr;
use function unpack;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;
use const PHP_INT_MIN;

/**
 * @internal Observes stalls; it cannot interrupt packet processing.
 */
final class PulseNetworkWatchdog extends Thread{
	public const MAX_REPORTS = 32;
	public string $record = "";
	private bool $stopped = false;

	public function __construct(private MainLoggerThread $writer, private int $generation, private int $stallNs = 5000000000){
		if($stallNs < 1){ throw new \InvalidArgumentException("Invalid Pulse stall threshold"); }
	}

	protected function onRun() : void{
		$reported = [];
		while(true){
			if($this->stopped){ break; }
			$record = $this->record;
			if($record !== "" && count($reported) < self::MAX_REPORTS){
				/** @var array{sequence: int, started: int, offset: int, tick: int, session: int, protocol: int, packet: int} $data */
				$data = unpack("Jsequence/Jstarted/Joffset/Jtick/Jprotocol/Nsession/Npacket", $record);
				$elapsed = (int) hrtime(true) - $data["started"];
				if($elapsed >= $this->stallNs && !isset($reported[$data["sequence"]]) && $this->record === $record){
					$reported[$data["sequence"]] = true;
					$phaseLength = ord($record[PulseNetworkWork::PREFIX_BYTES - 1]);
					$diagnostic = [
						"reason" => "network.stall_observed", "action" => "observe", "capture" => $this->generation,
						"capture_started_ns" => $data["started"] - $data["offset"],
						"offset_ns" => $data["offset"] + $elapsed, "started_offset_ns" => $data["offset"], "tick" => $data["tick"] === 0 ? null : $data["tick"],
						"session" => $data["session"] === 0 ? null : $data["session"],
						"protocol" => $data["protocol"] === PHP_INT_MIN ? null : $data["protocol"],
						"packet" => $data["packet"] === 1024 ? null : $data["packet"],
						"phase" => substr($record, PulseNetworkWork::PREFIX_BYTES, $phaseLength), "stage" => PulseNetworkWork::STAGES[ord($record[-1])],
						"elapsed_ns" => $elapsed, "threshold_ns" => $this->stallNs,
						"report_limit" => self::MAX_REPORTS, "report_limit_reached" => count($reported) === self::MAX_REPORTS
					];
					// Bypass console callbacks and their locks while the main thread may be stuck.
					$this->writer->write(date("Y-m-d H:i:s") . " [Pulse/NOTICE]: " . json_encode($diagnostic, JSON_THROW_ON_ERROR) . PHP_EOL);
				}
			}
			$this->synchronized(function() : void{
				if(!$this->stopped){ $this->wait(1000000); }
			});
		}
	}

	public function quit() : void{
		$this->synchronized(function() : void{
			$this->stopped = true;
			$this->record = "";
			$this->notify();
		});
		parent::quit();
	}
}
