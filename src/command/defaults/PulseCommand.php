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
use function array_shift;
use function count;
use function preg_match;
use function round;
use function sprintf;
use function strlen;
use function strtolower;

final class PulseCommand extends VanillaCommand{
	public function __construct(){
		parent::__construct("pulse", "Record and report server performance", "/pulse <start|stop|status|reset|report> [--duration 60s] [--spikes 50ms] [--max-spikes 32]");
		$this->setPermission(DefaultPermissionNames::COMMAND_PULSE);
	}

	/**
	 * @internal
	 * @param list<string> $args
	 * @return array{int, int, int}
	 */
	public static function parseOptions(array $args) : array{
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
		if(count($args) === 0){ throw new InvalidCommandSyntaxException(); }
		$mode = strtolower(array_shift($args));
		if($mode !== "start" && count($args) !== 0){ throw new InvalidCommandSyntaxException(); }
		$server = $sender->getServer();
		$pulse = $server->getPulse();
		try{
			switch($mode){
				case "start":
					$pulse->start(...self::parseOptions($args));
					Command::broadcastCommandMessage($sender, "Pulse started");
					break;
				case "stop":
					$pulse->stop();
					Command::broadcastCommandMessage($sender, "Pulse stopped");
					break;
				case "reset":
					$pulse->reset();
					Command::broadcastCommandMessage($sender, "Pulse reset");
					break;
				case "status":
					$session = $pulse->getSession();
					if($session === null){ $sender->sendMessage("Pulse is idle"); break; }
					$capture = $session->getCapture();
					$sender->sendMessage(sprintf("Pulse %s: %d ticks, average %.2f ms, worst %.2f ms, %d retained spikes",
						$session->isRecording() ? "recording" : "stopped", $capture["tick_count"],
						$capture["tick_count"] === 0 ? 0 : $capture["tick_total_ns"] / $capture["tick_count"] / 1000000,
						$capture["tick_max_ns"] / 1000000, count($capture["spikes"])
					));
					break;
				case "report":
					$server->createPulseReport()->onCompletion(
						fn(string $file) => Command::broadcastCommandMessage($sender, "Pulse report saved to $file"),
						fn() => $sender->sendMessage("Failed to create Pulse report")
					);
					$sender->sendMessage("Collecting Pulse report");
					break;
				default: throw new InvalidCommandSyntaxException();
			}
		}catch(InvalidCommandSyntaxException $e){
			throw $e;
		}catch(\LogicException $e){
			$sender->sendMessage($e->getMessage());
		}
		return true;
	}
}
