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
use quark\command\argument\ArgumentParseException;
use quark\command\argument\IntegerArgument;
use quark\command\argument\MappedArgument;
use quark\command\argument\PlayerArgument;
use quark\command\CommandSender;
use quark\item\enchantment\EnchantingHelper;
use quark\item\enchantment\Enchantment;
use quark\item\enchantment\EnchantmentInstance;
use quark\item\enchantment\StringToEnchantmentParser;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\utils\TextFormat;

class EnchantCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"enchant",
			KnownTranslationFactory::quark_command_enchant_description()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_ENCHANT_SELF,
			DefaultPermissionNames::COMMAND_ENCHANT_OTHER
		]);

		$this->syntax()
			->registerArguments([
				new PlayerArgument("target", partial: true, self: true),
				new MappedArgument(
					"enchantment",
					static fn(string $name) : Enchantment => StringToEnchantmentParser::getInstance()->parse($name) ?? throw new ArgumentParseException(KnownTranslationFactory::commands_enchant_notFound($name)),
					Enchantment::class
				),
				new IntegerArgument("level", 1, Limits::INT16_MAX, optional: true)
			])
			->handler($this->enchant(...));
	}

	private function enchant(CommandSender $sender, Player $target, Enchantment $enchantment, int $level = 1) : void{
		if(!$this->canTarget($sender, $target, DefaultPermissionNames::COMMAND_ENCHANT_SELF, DefaultPermissionNames::COMMAND_ENCHANT_OTHER)){
			return;
		}

		$item = $target->getMainHandItem();
		if($item->isNull()){
			$sender->sendMessage(KnownTranslationFactory::commands_enchant_noItem());
			return;
		}
		if($level > $enchantment->getMaxLevel()){
			$sender->sendMessage(KnownTranslationFactory::commands_generic_num_tooBig((string) $level, (string) $enchantment->getMaxLevel())->prefix(TextFormat::RED));
			return;
		}

		//this is necessary to deal with enchanted books, which are a different item type than regular books
		$target->setMainHandItem(EnchantingHelper::enchantItem($item, [new EnchantmentInstance($enchantment, $level)]));

		self::broadcastCommandMessage($sender, KnownTranslationFactory::commands_enchant_success($target->getName()));
	}
}
