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

namespace quark\command\defaults;

use quark\command\argument\DurationArgument;
use quark\command\argument\IntegerArgument;
use quark\command\Command;
use quark\command\CommandSender;
use quark\permission\DefaultPermissionNames;
use quark\pulse\PulseSession;
use quark\utils\TextFormat;
use function array_slice;
use function array_sum;
use function count;
use function implode;
use function intdiv;
use function max;
use function preg_replace;
use function round;
use function sprintf;

final class PulseCommand extends VanillaCommand{
	private const SPIKE_THRESHOLD_NS = 50000000;
	private const MAX_SPIKES = 32;
	private const PREFIX = TextFormat::DARK_GRAY . "[" . TextFormat::DARK_AQUA . "Pulse" . TextFormat::DARK_GRAY . "] " . TextFormat::GRAY;

	public function __construct(){
		parent::__construct("pulse", "Record and report server performance");
		$this->setPermission(DefaultPermissionNames::COMMAND_PULSE);
		$this->syntax()->handler($this->status(...));
		$this->syntax("status")->handler($this->status(...));
		$this->syntax("start")->registerArgument(new DurationArgument("time", 1e-9, 86400.0, optional: true))->handler($this->start(...));
		$this->syntax("stop")->handler($this->stop(...));
		$this->syntax("reset")->handler($this->reset(...));
		$this->syntax("top")->registerArgument(new IntegerArgument("count", 1, 10, optional: true))->handler($this->top(...));
		$this->syntax("spikes")->registerArgument(new IntegerArgument("count", 1, 10, optional: true))->handler($this->spikes(...));
		$this->syntax("plugins")->registerArgument(new IntegerArgument("count", 1, 10, optional: true))->handler($this->plugins(...));
		$this->syntax("workers")->handler($this->workers(...));
		$this->syntax("memory")->handler($this->memory(...));
		$this->syntax("network")->handler($this->network(...));
		$this->syntax("report")->handler($this->report(...));
		$this->syntax("help")->handler($this->help(...));
	}

	private static function msg(string $message) : string{
		return self::PREFIX . $message;
	}

	private static function value(string $value) : string{
		return TextFormat::WHITE . $value . TextFormat::GRAY;
	}

	private static function cmd(string $command) : string{
		return TextFormat::AQUA . $command . TextFormat::GRAY;
	}

	private static function ms(int|float $ns) : string{
		return self::value(sprintf("%.2f ms", $ns / 1000000));
	}

	private static function seconds(int|float $ns) : string{
		return self::value(sprintf("%.1fs", $ns / 1000000000));
	}

	private static function mib(int|float $bytes) : string{
		return self::value(sprintf("%.1f MiB", $bytes / 1048576));
	}

	private static function signedMib(int $bytes) : string{
		return self::value(sprintf("%+.1f MiB", $bytes / 1048576));
	}

	private static function clean(string $text) : string{
		return preg_replace('/[\x00-\x1f\x7f]/', " ", TextFormat::clean($text)) ?? "?";
	}

	/** @param list<array{string, int}> $costs */
	private static function costs(array $costs, int $limit) : string{
		$parts = [];
		foreach(array_slice($costs, 0, $limit) as [$name, $ns]){
			$parts[] = TextFormat::WHITE . $name . TextFormat::GRAY . " " . self::ms($ns);
		}
		return implode(", ", $parts);
	}

	private function session(CommandSender $sender) : ?PulseSession{
		$session = $sender->getServer()->getPulse()->getSession();
		if($session === null){
			self::sendStatus($sender);
		}
		return $session;
	}

	private function start(CommandSender $sender, ?float $time = null) : void{
		$duration = $time === null ? 0 : (int) round($time * 1000000000);
		try{
			$sender->getServer()->getPulse()->start($duration, self::SPIKE_THRESHOLD_NS, self::MAX_SPIKES);
		}catch(\LogicException $e){
			$sender->sendMessage(self::msg(TextFormat::RED . $e->getMessage()));
			return;
		}
		Command::broadcastCommandMessage($sender, self::msg(TextFormat::GREEN . "Recording " . TextFormat::GRAY . ($duration === 0 ? "until stopped" : "for " . self::seconds($duration))));
		$sender->sendMessage(self::msg("Run " . self::cmd("/pulse stop") . " to stop and save the report"));
	}

	private function stop(CommandSender $sender) : void{
		$pulse = $sender->getServer()->getPulse();
		if($pulse->getSession() === null){
			self::sendIdle($sender);
			return;
		}
		try{
			$pulse->stop();
		}catch(\LogicException $e){
			$sender->sendMessage(self::msg(TextFormat::RED . $e->getMessage()));
			return;
		}
		Command::broadcastCommandMessage($sender, self::msg(TextFormat::YELLOW . "Stopped"));
		self::sendStatus($sender);
		self::saveReport($sender);
	}

	private function reset(CommandSender $sender) : void{
		$pulse = $sender->getServer()->getPulse();
		try{
			$pulse->reset();
		}catch(\LogicException $e){
			$sender->sendMessage(self::msg(TextFormat::RED . $e->getMessage()));
			return;
		}
		Command::broadcastCommandMessage($sender, self::msg($pulse->isRecording() ? "Reset, recording restarted" :
			"Cleared" . ($pulse->getPendingOperations() > 0 ? ", waiting for workers to finish" : "")));
	}

	private function status(CommandSender $sender) : void{
		self::sendStatus($sender);
	}

	private function top(CommandSender $sender, ?int $count = null) : void{
		$session = $sender->getServer()->getPulse()->getSession();
		if($session === null){
			self::sendStatus($sender);
			return;
		}
		$top = $session->getTopZones($count ?? 5);
		if(count($top) === 0){
			$sender->sendMessage(self::msg("No completed measurements yet"));
			return;
		}
		$sender->sendMessage(self::msg("Top main-thread costs " . TextFormat::DARK_GRAY . "(self time)"));
		$sender->sendMessage(self::msg("By category: " . self::costs($session->getCategories(), 4)));
		foreach($top as $index => $zone){
			$name = self::clean($zone["name"]);
			$sender->sendMessage(TextFormat::DARK_GRAY . " " . ($index + 1) . ". " . TextFormat::WHITE . $name . TextFormat::DARK_GRAY . " - " . TextFormat::GRAY .
				self::ms($zone["self_ns"]) . " self, " . self::value((string) $zone["calls"]) . " calls, " . self::ms($zone["max_ns"]) . " max");
		}
	}

	private function report(CommandSender $sender) : void{
		self::saveReport($sender);
	}

	private function spikes(CommandSender $sender, ?int $count = null) : void{
		$session = $sender->getServer()->getPulse()->getSession();
		if($session === null){ self::sendStatus($sender); return; }
		$spikes = $session->getSpikes($count ?? 5);
		if(count($spikes) === 0){
			$sender->sendMessage(self::msg("No retained spikes yet"));
			return;
		}
		$breakdown = $session->getSpikeBreakdown();
		$sender->sendMessage(self::msg("Slowest retained main-thread spikes; all " . self::value((string) $breakdown["spikes"]) . " retained: " .
			self::costs($breakdown["categories"], 3) . ", unattributed " . self::ms($breakdown["unattributed_ns"])));
		foreach($spikes as $spike){
			$name = $spike["zone"] === null ? "no measured zone" : self::clean($spike["zone"]);
			$sender->sendMessage(self::msg("Tick " . self::value((string) $spike["tick_id"]) . " at " . self::seconds($spike["offset_ns"]) . ": " .
				self::ms($spike["duration_ns"]) . ", largest self cost " . TextFormat::WHITE . $name . TextFormat::GRAY . " " . self::ms($spike["self_ns"])));
			$sender->sendMessage(TextFormat::DARK_GRAY . "   " . TextFormat::GRAY . (count($spike["categories"]) === 0 ? "" : self::costs($spike["categories"], 3) . ", ") .
				"unattributed " . self::ms($spike["unattributed_ns"]));
		}
	}

	private function plugins(CommandSender $sender, ?int $count = null) : void{
		$session = $this->session($sender);
		if($session === null){ return; }
		$plugins = $session->getPlugins($count ?? 5);
		if(count($plugins) === 0){
			$sender->sendMessage(self::msg("No plugin work measured yet"));
			return;
		}
		$sender->sendMessage(self::msg("Plugin main-thread cost " . TextFormat::DARK_GRAY . "(total includes work they trigger; self excludes nested zones)"));
		foreach($plugins as $index => $plugin){
			$sender->sendMessage(TextFormat::DARK_GRAY . " " . ($index + 1) . ". " . TextFormat::WHITE . self::clean($plugin["name"]) . TextFormat::DARK_GRAY . " - " . TextFormat::GRAY .
				self::ms($plugin["total_ns"]) . ($plugin["tick_share"] === null ? "" : sprintf(" (%.1f%% of tick time)", $plugin["tick_share"] * 100)) . ", " .
				self::ms($plugin["self_ns"]) . " self, " . self::value((string) $plugin["calls"]) . " calls, avg " .
				self::ms($plugin["calls"] === 0 ? 0 : $plugin["total_ns"] / $plugin["calls"]) . ", max " . self::ms($plugin["max_ns"]));
		}
	}

	private function workers(CommandSender $sender) : void{
		$pulse = $sender->getServer()->getPulse();
		$queues = $pulse->getPool()->getTaskQueueSizes();
		$busy = 0;
		foreach($queues as $size){ $busy += $size > 0 ? 1 : 0; }
		$sender->sendMessage(self::msg("Async workers " . self::value(count($queues) . "/" . $pulse->getPool()->getSize()) . " running, " . self::value((string) $busy) .
			" busy, " . self::value((string) array_sum($queues)) . " tasks queued or running now"));
		$session = $pulse->getSession();
		$async = $session?->getCapture()["async"] ?? null;
		if($session === null || $async === null){
			$sender->sendMessage(self::msg("Start a recording for queue and latency metrics"));
			return;
		}
		$completed = max(1, $async["completed"]);
		$sender->sendMessage(self::msg("Recorded " . self::value((string) $async["submitted"]) . " submitted, " . self::value((string) $async["completed"]) .
			" completed, peak queue " . self::value((string) $async["queue_peak"]) . " on one worker"));
		$sender->sendMessage(self::msg("Queue wait avg " . self::ms(intdiv($async["wait_total_ns"], $completed)) . " max " . self::ms($async["wait_max_ns"]) .
			"; run avg " . self::ms(intdiv($async["run_total_ns"], $completed)) . " max " . self::ms($async["run_max_ns"]) .
			"; main-thread pickup avg " . self::ms(intdiv($async["delay_total_ns"], $completed)) . " max " . self::ms($async["delay_max_ns"])));
		$elapsed = max(1, $session->getStats()["elapsed_ns"]);
		foreach(array_slice($async["workers"], 0, 8) as [$worker, $tasks, $run, $heap, $heapPeak]){
			$sender->sendMessage(TextFormat::DARK_GRAY . " #$worker " . TextFormat::GRAY . self::value((string) $tasks) . " tasks, " .
				self::value(sprintf("%.1f%%", $run / $elapsed * 100)) . " busy, heap " . self::mib($heap) . " (peak " . self::mib($heapPeak) . ")");
		}
	}

	private function memory(CommandSender $sender) : void{
		$session = $this->session($sender);
		if($session === null){ return; }
		$resources = $session->getStats()["resources"];
		if($resources === null){
			$sender->sendMessage(self::msg("No main-thread memory samples"));
			return;
		}
		$sender->sendMessage(self::msg("PHP heap (main thread): " . self::mib($resources["memory_bytes"]) . " used, " . self::mib($resources["memory_allocated_bytes"]) .
			" allocated, " . self::mib($resources["memory_peak_bytes"]) . " sampled peak, " . self::signedMib($resources["memory_growth_bytes"]) . " since start"));
		$sender->sendMessage(self::msg($resources["rss_bytes"] === null || $resources["rss_peak_bytes"] === null ? "Process RSS unavailable on this platform" :
			"Process RSS: " . self::mib($resources["rss_bytes"]) . ", " . self::signedMib($resources["rss_growth_bytes"] ?? 0) . " since start, process peak " . self::mib($resources["rss_peak_bytes"])));
		$async = $session->getCapture()["async"] ?? null;
		$heaps = [];
		foreach($async["workers"] ?? [] as [$worker, , , $heap, $heapPeak]){
			$heaps[] = "#$worker " . sprintf("%.1f/%.1f", $heap / 1048576, $heapPeak / 1048576);
		}
		if(count($heaps) > 0){
			$sender->sendMessage(self::msg("Worker PHP heaps after recorded tasks, MiB last/peak: " . self::value(implode(", ", array_slice($heaps, 0, 8)))));
		}
		$sender->sendMessage(self::msg(TextFormat::DARK_GRAY . "RSS includes native allocations, thread stacks and mapped libraries; native heap use is not measured separately."));
	}

	private function network(CommandSender $sender) : void{
		$session = $this->session($sender);
		if($session === null){ return; }
		$network = $session->getNetwork();
		$sender->sendMessage(self::msg("Received " . self::value((string) $network["batches"]) . " batches, " . self::value((string) $network["packets"]) . " packets, " .
			self::mib($network["received_bytes"]) . " (" . self::mib($network["decompressed_bytes"]) . " decompressed)"));
		$sender->sendMessage(self::msg("Dropped: " . self::value((string) $network["decode_failed"]) . " undecodable, " . self::value((string) $network["state_dropped"]) .
			" wrong state, " . self::value((string) $network["repeated_dropped"]) . " repeated, " . self::value((string) $network["plugin_cancelled"]) . " cancelled by plugins; " .
			self::value((string) $network["enforcement_events"]) . " limit or validation events"));
		foreach($network["transports"] as $transport){
			$counters = [];
			foreach($transport["counters"] as [$key, $value]){ $counters[$key] = $value; }
			$sender->sendMessage(self::msg(TextFormat::WHITE . $transport["name"] . TextFormat::GRAY . ": sent " . self::mib($counters["bytes_sent"] ?? 0) . ", received " . self::mib($counters["bytes_received"] ?? 0) .
				(isset($counters["batches_sent"]) ? ", " . self::value((string) $counters["batches_sent"]) . " batches sent" : "") .
				(isset($counters["packets_rejected"]) ? ", " . self::value((string) $counters["packets_rejected"]) . " rejected" : "") .
				(isset($counters["unreliable_dropped"]) ? ", " . self::value((string) $counters["unreliable_dropped"]) . " unreliable dropped" : "") .
				(isset($counters["receive_pauses"]) ? ", " . self::value((string) $counters["receive_pauses"]) . " receive pauses" : "") .
				(isset($counters["event_stalls"]) ? ", " . self::value((string) $counters["event_stalls"]) . " event stalls" : "") .
				(isset($counters["overload_disconnects"]) ? ", " . self::value((string) $counters["overload_disconnects"]) . " overload kicks" : "") .
				(isset($counters["command_queue_peak_bytes"]) ? ", send queue peak " . self::mib($counters["command_queue_peak_bytes"]) : "")));
		}
		$costs = [];
		foreach($session->getCategories() as $cost){
			if($cost[0] === "network" || $cost[0] === "compression" || $cost[0] === "chunk sending"){ $costs[] = $cost; }
		}
		$sender->sendMessage(self::msg("Main-thread network self time: " . (count($costs) === 0 ? self::value("none measured") : self::costs($costs, 3))));
	}

	private function help(CommandSender $sender) : void{
		$sender->sendMessage(self::msg(self::cmd("/pulse start [time]") . " record, e.g. 60s, 5m, 1h"));
		$sender->sendMessage(self::msg(self::cmd("/pulse stop") . " stop and save the report"));
		$sender->sendMessage(self::msg(self::cmd("/pulse status") . " CPU, memory, ticks; " . self::cmd("/pulse top [1-10]") . " costs; " . self::cmd("/pulse spikes [1-10]") . " slow ticks"));
		$sender->sendMessage(self::msg(self::cmd("/pulse plugins [1-10]") . ", " . self::cmd("/pulse workers") . ", " . self::cmd("/pulse memory") . ", " . self::cmd("/pulse network") . " details"));
		$sender->sendMessage(self::msg(self::cmd("/pulse report") . " save without stopping, " . self::cmd("/pulse reset") . " clear or restart"));
	}

	private static function sendIdle(CommandSender $sender) : void{
		$sender->sendMessage(self::msg("Pulse is idle. Run " . self::cmd("/pulse start [time]")));
	}

	private static function sendStatus(CommandSender $sender) : void{
		$pulse = $sender->getServer()->getPulse();
		$session = $pulse->getSession();
		$pending = $pulse->getPendingOperations();
		if($session === null){
			if($pending > 0){
				$sender->sendMessage(self::msg("Waiting for " . self::value((string) $pending) . " worker operations"));
			}else{
				self::sendIdle($sender);
			}
			return;
		}
		$stats = $session->getStats();
		$sender->sendMessage(self::msg(($stats["recording"] ? TextFormat::GREEN . "Recording" : TextFormat::YELLOW . "Stopped") . TextFormat::GRAY . " for " .
			self::seconds($stats["elapsed_ns"]) . ", " . self::value((string) $stats["tick_count"]) . " ticks"));
		$sender->sendMessage(self::msg("Tick avg " . self::ms($stats["tick_count"] === 0 ? 0 : $stats["tick_total_ns"] / $stats["tick_count"]) .
			", worst " . self::ms($stats["tick_max_ns"]) . ", over 50 ms " . self::value((string) $stats["slow_tick_count"])));
		$sender->sendMessage(self::msg("Recent ticks (" . self::value((string) $stats["tick_sample_count"]) . "): p50 " . self::ms($stats["tick_p50_ns"]) .
			", p95 " . self::ms($stats["tick_p95_ns"]) . ", p99 " . self::ms($stats["tick_p99_ns"])));
		$sender->sendMessage(self::msg("Spikes " . self::value((string) ($stats["retained_spikes"] + $stats["spikes_dropped"])) .
			" total, " . self::value((string) $stats["retained_spikes"]) . " retained; " . self::cmd("/pulse spikes") . " for details"));
		$resources = $stats["resources"];
		if($resources !== null){
			$cpu = $resources["cpu_avg_percent"] === null ? "waiting for a full sampling interval or unavailable" : sprintf("%s, avg %.1f%%, peak %.1f%% (100%% = one core)",
				$resources["cpu_percent"] === null ? "unavailable" : sprintf("%.1f%%", $resources["cpu_percent"]), $resources["cpu_avg_percent"], $resources["cpu_peak_percent"]);
			$sender->sendMessage(self::msg("Recent process CPU " . self::value($cpu)));
			$sender->sendMessage(self::msg("PHP memory " . self::value(sprintf("%.1f MiB used, %.1f MiB allocated, %.1f MiB sampled peak",
				$resources["memory_bytes"] / 1048576, $resources["memory_allocated_bytes"] / 1048576, $resources["memory_peak_bytes"] / 1048576))));
		}
		if($stats["recording"] && $stats["duration_ns"] > 0){
			$sender->sendMessage(self::msg("Auto-stop in " . self::seconds(max(0, $stats["duration_ns"] - $stats["elapsed_ns"]))));
		}
		if($pending > 0){
			$sender->sendMessage(self::msg("Waiting for " . self::value((string) $pending) . " worker operations"));
		}
		if($stats["dropped_scopes"] > 0 || $stats["unbalanced_scopes"] > 0 || $stats["unbalanced_ticks"] > 0 || $stats["spikes_dropped"] > 0){
			$sender->sendMessage(self::msg(TextFormat::DARK_GRAY . sprintf("Notes: %d dropped scopes, %d repaired scopes, %d repaired ticks, %d old spikes discarded",
				$stats["dropped_scopes"], $stats["unbalanced_scopes"], $stats["unbalanced_ticks"], $stats["spikes_dropped"])));
		}
	}

	private static function saveReport(CommandSender $sender) : void{
		try{
			$promise = $sender->getServer()->createPulseReport();
		}catch(\LogicException $e){
			$sender->sendMessage(self::msg(TextFormat::RED . $e->getMessage()));
			return;
		}
		$sender->sendMessage(self::msg("Collecting report from all threads..."));
		$promise->observe(
			fn(string $file) => Command::broadcastCommandMessage($sender, self::msg(TextFormat::GREEN . "Report saved " . TextFormat::GRAY . "to " . self::value($file))),
			fn(\Throwable $e) => $sender->sendMessage(self::msg(TextFormat::RED . "Report failed: " . $e->getMessage() . TextFormat::GRAY . " (session kept)"))
		);
	}
}
