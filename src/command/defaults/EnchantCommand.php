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
use quark\item\enchantment\EnchantingHelper;
use quark\item\enchantment\EnchantmentInstance;
use quark\item\enchantment\StringToEnchantmentParser;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use function count;

class EnchantCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"enchant",
			KnownTranslationFactory::quark_command_enchant_description(),
			KnownTranslationFactory::commands_enchant_usage()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_ENCHANT_SELF,
			DefaultPermissionNames::COMMAND_ENCHANT_OTHER
		]);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) < 2){
			throw new InvalidCommandSyntaxException();
		}

		$player = $this->fetchPermittedPlayerTarget($sender, $args[0], DefaultPermissionNames::COMMAND_ENCHANT_SELF, DefaultPermissionNames::COMMAND_ENCHANT_OTHER);
		if($player === null){
			return true;
		}

		$item = $player->getInventory()->getItemInHand();

		if($item->isNull()){
			$sender->sendMessage(KnownTranslationFactory::commands_enchant_noItem());
			return true;
		}

		$enchantment = StringToEnchantmentParser::getInstance()->parse($args[1]);
		if($enchantment === null){
			$sender->sendMessage(KnownTranslationFactory::commands_enchant_notFound($args[1]));
			return true;
		}

		$level = 1;
		if(isset($args[2])){
			$level = $this->getBoundedInt($sender, $args[2], 1, $enchantment->getMaxLevel());
			if($level === null){
				return false;
			}
		}

		//this is necessary to deal with enchanted books, which are a different item type than regular books
		$enchantedItem = EnchantingHelper::enchantItem($item, [new EnchantmentInstance($enchantment, $level)]);
		$player->getInventory()->setItemInHand($enchantedItem);

		self::broadcastCommandMessage($sender, KnownTranslationFactory::commands_enchant_success($player->getName()));
		return true;
	}
}
