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

use quark\command\argument\IntegerArgument;
use quark\command\argument\StringArgument;
use quark\command\CommandSender;
use quark\command\SimpleCommandMap;
use quark\lang\KnownTranslationFactory;
use quark\lang\Translatable;
use quark\permission\DefaultPermissionNames;
use quark\utils\TextFormat;
use function array_chunk;
use function count;
use function explode;
use function implode;
use function ksort;
use function max;
use function min;
use function sort;
use function strtolower;
use const PHP_INT_MAX;
use const SORT_FLAG_CASE;
use const SORT_NATURAL;

class HelpCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"help",
			KnownTranslationFactory::quark_command_help_description(),
			null,
			["?"]
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_HELP);
		$this->syntax()->registerArgument(new IntegerArgument("page", optional: true))->handler($this->listCommands(...));
		$this->syntax()->registerArgument(new StringArgument("command", numbers: false))->handler($this->showCommand(...));
	}

	private function listCommands(CommandSender $sender, ?int $page = null) : void{
		$pageNumber = max(1, $page ?? 1);
		$commands = [];
		$commandMap = $sender->getServer()->getCommandMap();
		foreach($commandMap->getCommands() as $command){
			//a server alias can take over the label
			if($commandMap->getCommand($command->getLabel()) === $command && SimpleCommandMap::isOwnerEnabled($command) && $command->testPermissionSilent($sender)){
				$commands[$command->getLabel()] = $command;
			}
		}
		ksort($commands, SORT_NATURAL | SORT_FLAG_CASE);
		$commands = array_chunk($commands, $sender->getScreenLineHeight());
		$pageNumber = max(1, min(count($commands), $pageNumber));
		$sender->sendMessage(KnownTranslationFactory::commands_help_header((string) $pageNumber, (string) count($commands)));
		$lang = $sender->getLanguage();
		foreach($commands[$pageNumber - 1] ?? [] as $command){
			$description = $command->getDescription();
			$descriptionString = $description instanceof Translatable ? $lang->translate($description) : $description;
			$sender->sendMessage(TextFormat::DARK_GREEN . "/" . $command->getLabel() . ": " . TextFormat::RESET . $descriptionString);
		}
	}

	private function showCommand(CommandSender $sender, string $commandName) : void{
		$cmd = $sender->getServer()->getCommandMap()->getCommand(strtolower($commandName));
		if($cmd === null || !SimpleCommandMap::isOwnerEnabled($cmd) || !$cmd->testPermissionSilent($sender)){
			$sender->sendMessage(KnownTranslationFactory::quark_command_notFound($commandName, "/help")->prefix(TextFormat::RED));
			return;
		}

		$lang = $sender->getLanguage();
		$description = $cmd->getDescription();
		$descriptionString = $description instanceof Translatable ? $lang->translate($description) : $description;
		$sender->sendMessage(KnownTranslationFactory::quark_command_help_specificCommand_header($cmd->getLabel())
			->format(TextFormat::YELLOW . "--------- " . TextFormat::RESET, TextFormat::YELLOW . " ---------"));
		$sender->sendMessage(KnownTranslationFactory::quark_command_help_specificCommand_description(TextFormat::RESET . $descriptionString)
			->prefix(TextFormat::GOLD));

		$usage = $cmd->getUsage();
		$usageString = $usage instanceof Translatable ? $lang->translate($usage) : $usage;
		//declared syntaxes describe themselves unless a usage was set
		if($usageString === "/" . $cmd->getName()){
			$lines = [];
			foreach($cmd->getPreparedSyntaxes() ?? [] as $syntax){
				if($syntax->testPermissionSilent($sender)){
					$lines[] = $syntax->getUsage($cmd->getLabel());
				}
			}
			if(count($lines) !== 0){
				$usageString = implode("\n", $lines);
			}
		}
		$sender->sendMessage(KnownTranslationFactory::quark_command_help_specificCommand_usage(TextFormat::RESET . implode("\n" . TextFormat::RESET, explode("\n", $usageString, limit: PHP_INT_MAX)))
			->prefix(TextFormat::GOLD));

		$aliases = $cmd->getAliases();
		sort($aliases, SORT_NATURAL);
		$sender->sendMessage(KnownTranslationFactory::quark_command_help_specificCommand_aliases(TextFormat::RESET . implode(", ", $aliases))
			->prefix(TextFormat::GOLD));
	}
}
