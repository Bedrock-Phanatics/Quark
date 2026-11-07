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

	private const PREFIX = TextFormat::DARK_GRAY . "[" . TextFormat::DARK_AQUA . "Pulse" . TextFormat::DARK_GRAY . "] " . TextFormat::GRAY;

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

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		$mode = strtolower(array_shift($args) ?? "status");
		$server = $sender->getServer();
		$pulse = $server->getPulse();
		try{
			switch($mode){
				case "start":
					$duration = self::parseDuration($args);
					$pulse->start($duration, self::SPIKE_THRESHOLD_NS, self::MAX_SPIKES);
					Command::broadcastCommandMessage($sender, self::msg(TextFormat::GREEN . "Recording " . TextFormat::GRAY . ($duration === 0 ? "until stopped" : "for " . self::seconds($duration))));
					$sender->sendMessage(self::msg("Run " . self::cmd("/pulse stop") . " to stop and save the report"));
					break;
				case "stop":
					if(count($args) !== 0){ throw new InvalidCommandSyntaxException(); }
					if($pulse->getSession() === null){ self::sendIdle($sender); break; }
					$pulse->stop();
					Command::broadcastCommandMessage($sender, self::msg(TextFormat::YELLOW . "Stopped"));
					self::sendStatus($sender);
					self::saveReport($sender);
					break;
				case "reset":
					if(count($args) !== 0){ throw new InvalidCommandSyntaxException(); }
					$pulse->reset();
					Command::broadcastCommandMessage($sender, self::msg($pulse->isRecording() ? "Reset, recording restarted" :
						"Cleared" . ($pulse->getPendingOperations() > 0 ? ", waiting for workers to finish" : "")));
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
					if(count($top) === 0){ $sender->sendMessage(self::msg("No completed measurements yet")); break; }
					$sender->sendMessage(self::msg("Top main-thread costs " . TextFormat::DARK_GRAY . "(self time)"));
					foreach($top as $index => $zone){
						$name = preg_replace('/[\x00-\x1f\x7f]/', " ", TextFormat::clean($zone["name"])) ?? "zone";
						$sender->sendMessage(TextFormat::DARK_GRAY . " " . ($index + 1) . ". " . TextFormat::WHITE . $name . TextFormat::DARK_GRAY . " - " . TextFormat::GRAY .
							self::ms($zone["self_ns"]) . " self, " . self::value((string) $zone["calls"]) . " calls, " . self::ms($zone["max_ns"]) . " max");
					}
					break;
				case "report":
					if(count($args) !== 0){ throw new InvalidCommandSyntaxException(); }
					self::saveReport($sender);
					break;
				case "help":
					if(count($args) !== 0){ throw new InvalidCommandSyntaxException(); }
					$sender->sendMessage(self::msg(self::cmd("/pulse start [time]") . " record, e.g. 60s, 5m, 1h"));
					$sender->sendMessage(self::msg(self::cmd("/pulse stop") . " stop and save the report"));
					$sender->sendMessage(self::msg(self::cmd("/pulse status") . " session info, " . self::cmd("/pulse top [1-10]") . " main-thread costs"));
					$sender->sendMessage(self::msg(self::cmd("/pulse report") . " save without stopping, " . self::cmd("/pulse reset") . " clear or restart"));
					break;
				default: throw new InvalidCommandSyntaxException();
			}
		}catch(InvalidCommandSyntaxException){
			$sender->sendMessage(self::msg("Usage: " . self::cmd(match($mode){
				"start" => "/pulse start [time]",
				"stop" => "/pulse stop",
				"top" => "/pulse top [1-10]",
				default => "/pulse <start|stop|status|top|report|reset|help>"
			}) . ($mode === "start" ? TextFormat::DARK_GRAY . " (e.g. 60s, 5m, 1h; max 24h)" : "")));
		}catch(\LogicException $e){
			$sender->sendMessage(self::msg(TextFormat::RED . $e->getMessage()));
		}
		return true;
	}

	private static function sendIdle(CommandSender $sender) : void{
		$sender->sendMessage(self::msg("Pulse is idle. Run " . self::cmd("/pulse start [time]")));
	}

	private static function sendStatus(CommandSender $sender) : void{
		$pulse = $sender->getServer()->getPulse();
		$session = $pulse->getSession();
		$pending = $pulse->getPendingOperations();
		if($session === null){
			if($pending > 0){ $sender->sendMessage(self::msg("Waiting for " . self::value((string) $pending) . " worker operations")); }
			else{ self::sendIdle($sender); }
			return;
		}
		$stats = $session->getStats();
		$sender->sendMessage(self::msg(($stats["recording"] ? TextFormat::GREEN . "Recording" : TextFormat::YELLOW . "Stopped") . TextFormat::GRAY . " for " .
			self::seconds($stats["elapsed_ns"]) . ", " . self::value((string) $stats["tick_count"]) . " ticks"));
		$sender->sendMessage(self::msg("Tick avg " . self::ms($stats["tick_count"] === 0 ? 0 : $stats["tick_total_ns"] / $stats["tick_count"]) .
			", worst " . self::ms($stats["tick_max_ns"]) . ", spikes " . self::value((string) $stats["retained_spikes"])));
		if($stats["recording"] && $stats["duration_ns"] > 0){
			$sender->sendMessage(self::msg("Auto-stop in " . self::seconds(max(0, $stats["duration_ns"] - $stats["elapsed_ns"]))));
		}
		if($pending > 0){ $sender->sendMessage(self::msg("Waiting for " . self::value((string) $pending) . " worker operations")); }
		if($stats["dropped_scopes"] > 0 || $stats["unbalanced_scopes"] > 0 || $stats["unbalanced_ticks"] > 0 || $stats["spikes_dropped"] > 0){
			$sender->sendMessage(self::msg(TextFormat::DARK_GRAY . sprintf("Notes: %d dropped scopes, %d repaired scopes, %d repaired ticks, %d old spikes discarded",
				$stats["dropped_scopes"], $stats["unbalanced_scopes"], $stats["unbalanced_ticks"], $stats["spikes_dropped"])));
		}
	}

	private static function saveReport(CommandSender $sender) : void{
		$promise = $sender->getServer()->createPulseReport();
		$sender->sendMessage(self::msg("Collecting report from all threads..."));
		$promise->observe(
			fn(string $file) => Command::broadcastCommandMessage($sender, self::msg(TextFormat::GREEN . "Report saved " . TextFormat::GRAY . "to " . self::value($file))),
			fn(\Throwable $e) => $sender->sendMessage(self::msg(TextFormat::RED . "Report failed: " . $e->getMessage() . TextFormat::GRAY . " (session kept)"))
		);
	}
}
