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
use quark\command\CommandSender;
use quark\entity\Attribute;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\utils\AssumptionFailedError;
use quark\utils\TextFormat;
use function abs;
use function max;
use function min;

class XpCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"xp",
			KnownTranslationFactory::quark_command_xp_description()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_XP_SELF,
			DefaultPermissionNames::COMMAND_XP_OTHER
		]);

		$amount = new IntegerArgument("amount", Limits::INT32_MIN, Limits::INT32_MAX);
		$levels = new XpLevelsArgument("levels");
		$target = new PlayerArgument("target", partial: true, self: true);

		$this->syntax()->permission(DefaultPermissionNames::COMMAND_XP_SELF)->registerArgument($amount)->handler($this->pointsToSelf(...));
		$this->syntax()->registerArguments([$amount, $target])->handler($this->pointsToOther(...));
		$this->syntax()->permission(DefaultPermissionNames::COMMAND_XP_SELF)->registerArgument($levels)->handler($this->levelsToSelf(...));
		$this->syntax()->registerArguments([$levels, $target])->handler($this->levelsToOther(...));
	}

	private function pointsToSelf(Player $sender, int $amount) : void{
		$this->addPoints($sender, $sender, $amount);
	}

	private function pointsToOther(CommandSender $sender, int $amount, Player $target) : void{
		if($this->canTarget($sender, $target, DefaultPermissionNames::COMMAND_XP_SELF, DefaultPermissionNames::COMMAND_XP_OTHER)){
			$this->addPoints($sender, $target, $amount);
		}
	}

	private function levelsToSelf(Player $sender, int $levels) : void{
		$this->addLevels($sender, $sender, $levels);
	}

	private function levelsToOther(CommandSender $sender, int $levels, Player $target) : void{
		if($this->canTarget($sender, $target, DefaultPermissionNames::COMMAND_XP_SELF, DefaultPermissionNames::COMMAND_XP_OTHER)){
			$this->addLevels($sender, $target, $levels);
		}
	}

	private function addPoints(CommandSender $sender, Player $target, int $amount) : void{
		if($amount < 0){
			$sender->sendMessage(KnownTranslationFactory::commands_xp_failure_widthdrawXp()->prefix(TextFormat::RED));
			return;
		}

		$target->getXpManager()->addXp($amount, false);
		$sender->sendMessage(KnownTranslationFactory::commands_xp_success((string) $amount, $target->getName()));
	}

	private function addLevels(CommandSender $sender, Player $target, int $levels) : void{
		$xpManager = $target->getXpManager();
		$maxLevel = (int) ($target->getAttributeMap()->get(Attribute::EXPERIENCE_LEVEL) ?? throw new AssumptionFailedError())->getMaxValue();
		$currentLevel = $xpManager->getXpLevel();
		$levels = max(-$currentLevel, min($levels, $maxLevel - $currentLevel));

		if($levels >= 0){
			$xpManager->addXpLevels($levels, false);
			$sender->sendMessage(KnownTranslationFactory::commands_xp_success_levels((string) $levels, $target->getName()));
		}else{
			$levels = abs($levels);
			$xpManager->subtractXpLevels($levels);
			$sender->sendMessage(KnownTranslationFactory::commands_xp_success_negative_levels((string) $levels, $target->getName()));
		}
	}
}
