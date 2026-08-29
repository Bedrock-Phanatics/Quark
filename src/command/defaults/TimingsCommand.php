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
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\scheduler\BulkCurlTask;
use quark\scheduler\BulkCurlTaskOperation;
use quark\timings\TimingsHandler;
use quark\utils\AssumptionFailedError;
use quark\utils\InternetException;
use quark\utils\InternetRequestResult;
use quark\YmlServerProperties;
use Symfony\Component\Filesystem\Path;
use function count;
use function http_build_query;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function strtolower;
use const CURLOPT_AUTOREFERER;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POST;
use const CURLOPT_POSTFIELDS;

class TimingsCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"timings",
			KnownTranslationFactory::quark_command_timings_description(),
			KnownTranslationFactory::quark_command_timings_usage()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_TIMINGS);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) !== 1){
			throw new InvalidCommandSyntaxException();
		}

		$mode = strtolower($args[0]);

		if($mode === "on"){
			if(TimingsHandler::isEnabled()){
				$sender->sendMessage(KnownTranslationFactory::quark_command_timings_alreadyEnabled());
				return true;
			}
			TimingsHandler::setEnabled();
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::quark_command_timings_enable());

			return true;
		}elseif($mode === "off"){
			TimingsHandler::setEnabled(false);
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::quark_command_timings_disable());
			return true;
		}

		if(!TimingsHandler::isEnabled()){
			$sender->sendMessage(KnownTranslationFactory::quark_command_timings_timingsDisabled());

			return true;
		}

		$paste = $mode === "paste";

		if($mode === "reset"){
			TimingsHandler::reload();
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::quark_command_timings_reset());
		}elseif($mode === "merged" || $mode === "report" || $paste){
			if($paste){
				$timingsPromise = TimingsHandler::requestPrintTimings();
				Command::broadcastCommandMessage($sender, KnownTranslationFactory::quark_command_timings_collect());
				$timingsPromise->onCompletion(
					fn(array $lines) => $this->uploadReport($lines, $sender),
					fn() => throw new AssumptionFailedError("This promise is not expected to be rejected")
				);
			}else{
				TimingsHandler::createReportFile(Path::join($sender->getServer()->getDataPath(), "timings"))->onCompletion(
					function(string $timingsFile) use ($sender) : void{
						Command::broadcastCommandMessage($sender, KnownTranslationFactory::quark_command_timings_timingsWrite($timingsFile));
					},
					fn() => $sender->getServer()->getLogger()->error("Failed to create timings report file")
				);
			}
		}else{
			throw new InvalidCommandSyntaxException();
		}

		return true;
	}

	/**
	 * @param string[] $lines
	 * @phpstan-param list<string> $lines
	 */
	private function uploadReport(array $lines, CommandSender $sender) : void{
		$data = [
			"browser" => $agent = $sender->getServer()->getName() . " " . $sender->getServer()->getQuarkVersion(),
			"data" => implode("\n", $lines),
			"private" => "true"
		];

		$host = $sender->getServer()->getConfigGroup()->getPropertyString(YmlServerProperties::TIMINGS_HOST, "timings.pmmp.io");

		$sender->getServer()->getAsyncPool()->submitTask(new BulkCurlTask(
			[new BulkCurlTaskOperation(
				"https://$host?upload=true",
				10,
				[],
				[
					CURLOPT_HTTPHEADER => [
						"User-Agent: $agent",
						"Content-Type: application/x-www-form-urlencoded"
					],
					CURLOPT_POST => true,
					CURLOPT_POSTFIELDS => http_build_query($data),
					CURLOPT_AUTOREFERER => false,
					CURLOPT_FOLLOWLOCATION => false
				]
			)],
			function(array $results) use ($sender, $host) : void{
				/** @phpstan-var array<InternetRequestResult|InternetException> $results */
				if($sender instanceof Player && !$sender->isOnline()){ // TODO replace with a more generic API method for checking availability of CommandSender
					return;
				}
				$result = $results[0];
				if($result instanceof InternetException){
					$sender->getServer()->getLogger()->logException($result);
					return;
				}
				$response = json_decode($result->getBody(), true);
				if(is_array($response) && isset($response["id"]) && (is_int($response["id"]) || is_string($response["id"]))){
					$url = "https://" . $host . "/?id=" . $response["id"];
					if(isset($response["access_token"]) && is_string($response["access_token"])){
						$url .= "&access_token=" . $response["access_token"];
					}else{
						$sender->getServer()->getLogger()->warning("Your chosen timings host does not support private reports. Anyone will be able to see your report if they guess the ID.");
					}
					Command::broadcastCommandMessage($sender, KnownTranslationFactory::quark_command_timings_timingsRead($url));
				}else{
					$sender->getServer()->getLogger()->debug("Invalid response from timings server (" . $result->getCode() . "): " . $result->getBody());
					Command::broadcastCommandMessage($sender, KnownTranslationFactory::quark_command_timings_pasteError());
				}
			}
		));
	}
}
