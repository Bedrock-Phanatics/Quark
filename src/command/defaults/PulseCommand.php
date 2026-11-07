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
use function strlen;
use function strtolower;

final class PulseCommand extends VanillaCommand{
	public function __construct(){
		parent::__construct("pulse", "Record and report server performance", "/pulse <start [time]|stop|status|top|report|reset|help>");
		$this->setPermission(DefaultPermissionNames::COMMAND_PULSE);
	}

	private const SPIKE_THRESHOLD_NS = 50000000;
	private const MAX_SPIKES = 32;

	/**
	 * @internal
	 * @param list<string> $args
	 */
	public static function parseDuration(array $args) : int{
		if(count($args) === 0){ return 0; }
		if(count($args) > 1 || strlen($args[0]) > 24 || preg_match('/^([0-9]+(?:\.[0-9]+)?)(ms|s|m|h)?$/D', strtolower($args[0]), $matches) !== 1){ throw new InvalidCommandSyntaxException(); }
		$factor = match($matches[2] ?? ""){ "ms" => 1000000, "", "s" => 1000000000, "m" => 60000000000, "h" => 3600000000000 };
		$ns = round((float) $matches[1] * $factor);
		if($ns < 1 || $ns > 86400000000000){ throw new InvalidCommandSyntaxException(); }
		return (int) $ns;
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		$mode = strtolower(array_shift($args) ?? "status");
		$server = $sender->getServer();
		$pulse = $server->getPulse();
		try{
			switch($mode){
				case "start":
					$duration = self::parseDuration($args);
					$pulse->start($duration, self::SPIKE_THRESHOLD_NS, self::MAX_SPIKES);
					Command::broadcastCommandMessage($sender, "Pulse started " . ($duration === 0 ? "until stopped" : sprintf("for %.1f s", $duration / 1000000000)));
					$sender->sendMessage("Use /pulse stop to stop and save the report");
					break;
				case "stop":
					if(count($args) !== 0){ throw new InvalidCommandSyntaxException(); }
					if($pulse->getSession() === null){ $sender->sendMessage("Pulse is idle. Start with /pulse start [time]"); break; }
					$pulse->stop();
					Command::broadcastCommandMessage($sender, "Pulse stopped");
					self::sendStatus($sender);
					self::saveReport($sender);
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
					$sender->sendMessage("/pulse start [time] - record, e.g. 60s, 5m, 1h; until stopped if omitted");
					$sender->sendMessage("/pulse stop - stop and save the report");
					$sender->sendMessage("/pulse status - show session and tick costs; /pulse top [1-10] - main-thread costs");
					$sender->sendMessage("/pulse report - save without stopping; /pulse reset - clear or restart");
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
			$sender->sendMessage($pending > 0 ? "Pulse is waiting for $pending worker operations" : "Pulse is idle. Start with /pulse start [time]");
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
		$promise->observe(
			fn(string $file) => Command::broadcastCommandMessage($sender, "Pulse report saved to $file"),
			fn(\Throwable $e) => $sender->sendMessage("Pulse report failed: " . $e->getMessage() . ". The session is retained; try /pulse status.")
		);
	}
}
