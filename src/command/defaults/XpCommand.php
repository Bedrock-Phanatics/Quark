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

use quark\command\CommandSender;
use quark\command\utils\InvalidCommandSyntaxException;
use quark\entity\Attribute;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\utils\AssumptionFailedError;
use quark\utils\Limits;
use quark\utils\TextFormat;
use function abs;
use function count;
use function str_ends_with;
use function substr;

class XpCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"xp",
			KnownTranslationFactory::quark_command_xp_description(),
			KnownTranslationFactory::quark_command_xp_usage()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_XP_SELF,
			DefaultPermissionNames::COMMAND_XP_OTHER
		]);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 1){
			throw new InvalidCommandSyntaxException();
		}

		$player = $this->fetchPermittedPlayerTarget($sender, $args[1] ?? null, DefaultPermissionNames::COMMAND_XP_SELF, DefaultPermissionNames::COMMAND_XP_OTHER);
		if($player === null){
			return true;
		}

		$xpManager = $player->getXpManager();
		if(str_ends_with($args[0], "L")){
			$xpLevelAttr = $player->getAttributeMap()->get(Attribute::EXPERIENCE_LEVEL) ?? throw new AssumptionFailedError();
			$maxXpLevel = (int) $xpLevelAttr->getMaxValue();
			$currentXpLevel = $xpManager->getXpLevel();
			$xpLevels = $this->getInteger($sender, substr($args[0], 0, -1), -$currentXpLevel, $maxXpLevel - $currentXpLevel);
			if($xpLevels >= 0){
				$xpManager->addXpLevels($xpLevels, false);
				$sender->sendMessage(KnownTranslationFactory::commands_xp_success_levels((string) $xpLevels, $player->getName()));
			}else{
				$xpLevels = abs($xpLevels);
				$xpManager->subtractXpLevels($xpLevels);
				$sender->sendMessage(KnownTranslationFactory::commands_xp_success_negative_levels((string) $xpLevels, $player->getName()));
			}
		}else{
			$xp = $this->getInteger($sender, $args[0], max: Limits::INT32_MAX);
			if($xp < 0){
				$sender->sendMessage(KnownTranslationFactory::commands_xp_failure_widthdrawXp()->prefix(TextFormat::RED));
			}else{
				$xpManager->addXp($xp, false);
				$sender->sendMessage(KnownTranslationFactory::commands_xp_success((string) $xp, $player->getName()));
			}
		}

		return true;
	}
}
