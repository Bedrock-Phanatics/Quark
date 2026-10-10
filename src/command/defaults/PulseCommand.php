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

use quark\command\Command;
use quark\command\CommandSender;
use quark\command\utils\InvalidCommandSyntaxException;
use quark\permission\DefaultPermissionNames;
use quark\utils\TextFormat;
use function array_shift;
use function count;
use function max;
use function preg_match;
use function preg_replace;
use function round;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strtolower;

final class PulseCommand extends VanillaCommand{
	public function __construct(){
		parent::__construct("pulse", "Record and report server performance", "/pulse [status|start|stop|top|reset|report|help]");
		$this->setPermission(DefaultPermissionNames::COMMAND_PULSE);
	}

	/**
	 * @internal
	 * @param list<string> $args
	 * @return array{int, int, int}
	 */
	public static function parseOptions(array $args) : array{
		if(count($args) > 0 && !str_starts_with($args[0], "--")){
			$args = ["--duration", array_shift($args), ...$args];
		}
		if(count($args) > 6 || count($args) % 2 !== 0){ throw new InvalidCommandSyntaxException(); }
		$duration = $threshold = 0;
		$maxSpikes = 32;
		$seen = [];
		while(count($args) > 0){
			$option = array_shift($args);
			$value = array_shift($args);
			if($option === null || $value === null || isset($seen[$option])){ throw new InvalidCommandSyntaxException(); }
			$seen[$option] = true;
			if($option === "--max-spikes"){
				if(preg_match('/^[1-9][0-9]{0,2}$/D', $value) !== 1 || (int) $value > 128){ throw new InvalidCommandSyntaxException(); }
				$maxSpikes = (int) $value;
			}elseif($option === "--duration" || $option === "--spikes"){
				if(strlen($value) > 24 || preg_match('/^([0-9]+(?:\.[0-9]+)?)(ms|s|m)$/D', $value, $matches) !== 1){ throw new InvalidCommandSyntaxException(); }
				$factor = match($matches[2]){ "ms" => 1000000, "s" => 1000000000, "m" => 60000000000 };
				$ns = round((float) $matches[1] * $factor);
				$limit = $option === "--duration" ? 86400000000000 : 60000000000;
				if($ns < 1 || $ns > $limit){ throw new InvalidCommandSyntaxException(); }
				if($option === "--duration"){ $duration = (int) $ns; }else{ $threshold = (int) $ns; }
			}else{
				throw new InvalidCommandSyntaxException();
			}
		}
		return [$duration, $threshold, $maxSpikes];
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		$mode = strtolower(array_shift($args) ?? "status");
		$server = $sender->getServer();
		$pulse = $server->getPulse();
		try{
			switch($mode){
				case "start":
					[$duration, $threshold, $maxSpikes] = self::parseOptions($args);
					$pulse->start($duration, $threshold, $maxSpikes);
					Command::broadcastCommandMessage($sender, "Pulse started: " . ($duration === 0 ? "until stopped" : sprintf("%.2f s", $duration / 1000000000)) .
						($threshold === 0 ? "; spikes off" : sprintf("; spikes over %.2f ms, retaining %d", $threshold / 1000000, $maxSpikes)));
					$sender->sendMessage("Use /pulse top to inspect or /pulse stop --report to stop and save");
					break;
				case "stop":
					if($args !== [] && $args !== ["--report"]){ throw new InvalidCommandSyntaxException(); }
					if($pulse->getSession() === null){ $sender->sendMessage("Pulse is idle. Start with /pulse start 60s --spikes 50ms"); break; }
					$pulse->stop();
					Command::broadcastCommandMessage($sender, "Pulse stopped; capture retained");
					self::sendStatus($sender);
					if($args === ["--report"]){ self::saveReport($sender); }
					else{ $sender->sendMessage("Use /pulse report to save or /pulse top to inspect"); }
					break;
				case "reset":
					if(count($args) !== 0){ throw new InvalidCommandSyntaxException(); }
					$pulse->reset();
					Command::broadcastCommandMessage($sender, $pulse->isRecording() ? "Pulse reset; recording restarted with the same options" :
						"Pulse cleared" . ($pulse->getPendingOperations() > 0 ? "; waiting for workers to finish" : "; ready to start"));
					break;
				case "status":
					if(count($args) !== 0){ throw new InvalidCommandSyntaxException(); }
					self::sendStatus($sender);
					break;
				case "top":
					if(count($args) > 1 || (isset($args[0]) && preg_match('/^(?:[1-9]|10)$/D', $args[0]) !== 1)){ throw new InvalidCommandSyntaxException(); }
					$session = $pulse->getSession();
					if($session === null){ self::sendStatus($sender); break; }
					$top = $session->getTopZones(isset($args[0]) ? (int) $args[0] : 5);
					if(count($top) === 0){ $sender->sendMessage("Pulse has no completed measurements yet"); break; }
					$sender->sendMessage("Pulse top: main thread, ranked by self time (excluding child work)");
					foreach($top as $index => $zone){
						$name = preg_replace('/[\x00-\x1f\x7f]/', " ", TextFormat::clean($zone["name"])) ?? "zone";
						$sender->sendMessage(sprintf("%d. %s: %.2f ms self, %d calls, %.2f ms max total", $index + 1, $name, $zone["self_ns"] / 1000000, $zone["calls"], $zone["max_ns"] / 1000000));
					}
					break;
				case "report":
					if(count($args) !== 0){ throw new InvalidCommandSyntaxException(); }
					self::saveReport($sender);
					break;
				case "help":
					if(count($args) !== 0){ throw new InvalidCommandSyntaxException(); }
					$sender->sendMessage("/pulse [status] - show session and tick costs");
					$sender->sendMessage("/pulse start [60s] [--spikes 50ms] [--max-spikes 32] - record; --duration 60s also works");
					$sender->sendMessage("/pulse stop [--report] - stop; optionally save all threads");
					$sender->sendMessage("/pulse top [1-10] - show main-thread costs; /pulse report - save all threads without stopping");
					$sender->sendMessage("/pulse reset - clear stopped data or restart an active session");
					break;
				default: throw new InvalidCommandSyntaxException();
			}
		}catch(InvalidCommandSyntaxException){
			$sender->sendMessage(match($mode){
				"start" => "Usage: /pulse start [60s] [--duration 60s] [--spikes 50ms] [--max-spikes 1-128]. Use one duration; units: ms, s, m.",
				"stop" => "Usage: /pulse stop [--report]",
				"top" => "Usage: /pulse top [1-10]",
				default => "Usage: /pulse [status|start|stop|top|reset|report|help]. Use /pulse help for examples."
			});
		}catch(\LogicException $e){
			$sender->sendMessage($e->getMessage());
		}
		return true;
	}

	private static function sendStatus(CommandSender $sender) : void{
		$pulse = $sender->getServer()->getPulse();
		$session = $pulse->getSession();
		if($session === null){
			$pending = $pulse->getPendingOperations();
			$sender->sendMessage($pending > 0 ? "Pulse is waiting for $pending worker operations" : "Pulse is idle. Start with /pulse start 60s --spikes 50ms");
			return;
		}
		$stats = $session->getStats();
		$sender->sendMessage(sprintf("Pulse %s: %.1f s, %d ticks | average %.2f ms, worst %.2f ms | %d retained spikes",
			$stats["recording"] ? "recording" : "stopped", $stats["elapsed_ns"] / 1000000000, $stats["tick_count"],
			$stats["tick_count"] === 0 ? 0 : $stats["tick_total_ns"] / $stats["tick_count"] / 1000000,
			$stats["tick_max_ns"] / 1000000, $stats["retained_spikes"]
		));
		if($stats["recording"] && $stats["duration_ns"] > 0){
			$sender->sendMessage(sprintf("Auto-stop in %.1f s; use /pulse report to save the capture", max(0, $stats["duration_ns"] - $stats["elapsed_ns"]) / 1000000000));
		}
		if(($pending = $pulse->getPendingOperations()) > 0){ $sender->sendMessage("Pulse is waiting for $pending worker operations"); }
		if($stats["dropped_scopes"] > 0 || $stats["unbalanced_scopes"] > 0 || $stats["unbalanced_ticks"] > 0 || $stats["spikes_dropped"] > 0){
			$sender->sendMessage(sprintf("Capture notes: %d dropped scopes, %d repaired scopes, %d repaired ticks, %d older spikes discarded",
				$stats["dropped_scopes"], $stats["unbalanced_scopes"], $stats["unbalanced_ticks"], $stats["spikes_dropped"]
			));
		}
	}

	private static function saveReport(CommandSender $sender) : void{
		$promise = $sender->getServer()->createPulseReport();
		$sender->sendMessage("Collecting Pulse report from all threads (up to 30 s)");
		$promise->onCompletion(
			fn(string $file) => Command::broadcastCommandMessage($sender, "Pulse report saved to $file"),
			fn() => $sender->sendMessage("Pulse report failed: workers timed out or the report could not be saved. The session is retained; try /pulse status.")
		);
	}
}
