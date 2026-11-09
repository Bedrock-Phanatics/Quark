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

use pocketmine\utils\Limits;
use quark\command\argument\IntegerArgument;
use quark\command\argument\PlayerArgument;
use quark\command\argument\StringArgument;
use quark\command\CommandSender;
use quark\command\CommandSyntax;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;

class TitleCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"title",
			KnownTranslationFactory::quark_command_title_description()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_TITLE_SELF,
			DefaultPermissionNames::COMMAND_TITLE_OTHER
		]);

		$this->targeting("clear")->handler($this->clear(...));
		$this->targeting("reset")->handler($this->reset(...));
		$this->targeting("title")->registerArgument(new StringArgument("text", greedy: true))->handler($this->title(...));
		$this->targeting("subtitle")->registerArgument(new StringArgument("text", greedy: true))->handler($this->subtitle(...));
		$this->targeting("actionbar")->registerArgument(new StringArgument("text", greedy: true))->handler($this->actionbar(...));
		$this->targeting("times")
			->registerArguments([
				new IntegerArgument("fadeIn", 0, Limits::INT32_MAX),
				new IntegerArgument("stay", 0, Limits::INT32_MAX),
				new IntegerArgument("fadeOut", 0, Limits::INT32_MAX)
			])
			->handler($this->times(...));
	}

	private function targeting(string $word) : CommandSyntax{
		return $this->syntax()
			->registerArgument(new PlayerArgument("target", partial: true, self: true))
			->literal($word);
	}

	private function clear(CommandSender $sender, Player $target) : void{
		$this->apply($sender, $target, $target->removeTitles(...));
	}

	private function reset(CommandSender $sender, Player $target) : void{
		$this->apply($sender, $target, $target->resetTitles(...));
	}

	private function title(CommandSender $sender, Player $target, string $text) : void{
		$this->apply($sender, $target, static fn() => $target->sendTitle($text));
	}

	private function subtitle(CommandSender $sender, Player $target, string $text) : void{
		$this->apply($sender, $target, static fn() => $target->sendSubTitle($text));
	}

	private function actionbar(CommandSender $sender, Player $target, string $text) : void{
		$this->apply($sender, $target, static fn() => $target->sendActionBarMessage($text));
	}

	private function times(CommandSender $sender, Player $target, int $fadeIn, int $stay, int $fadeOut) : void{
		$this->apply($sender, $target, static fn() => $target->setTitleDuration($fadeIn, $stay, $fadeOut));
	}

	/**
	 * @phpstan-param \Closure() : void $action
	 */
	private function apply(CommandSender $sender, Player $target, \Closure $action) : void{
		if($this->canTarget($sender, $target, DefaultPermissionNames::COMMAND_TITLE_SELF, DefaultPermissionNames::COMMAND_TITLE_OTHER)){
			$action();
			$sender->sendMessage(KnownTranslationFactory::commands_title_success());
		}
	}
}
