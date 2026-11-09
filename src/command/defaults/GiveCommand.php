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

use pocketmine\nbt\JsonNbtParser;
use pocketmine\nbt\NbtException;
use quark\command\argument\ArgumentParseException;
use quark\command\argument\IntegerArgument;
use quark\command\argument\MappedArgument;
use quark\command\argument\PlayerArgument;
use quark\command\argument\StringArgument;
use quark\command\Command;
use quark\command\CommandSender;
use quark\item\Item;
use quark\item\LegacyStringToItemParser;
use quark\item\LegacyStringToItemParserException;
use quark\item\StringToItemParser;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\utils\TextFormat;

class GiveCommand extends VanillaCommand{
	private const MAX_COUNT = 32767;

	public function __construct(){
		parent::__construct(
			"give",
			KnownTranslationFactory::quark_command_give_description()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_GIVE_SELF,
			DefaultPermissionNames::COMMAND_GIVE_OTHER
		]);

		$this->syntax()
			->registerArguments([
				new PlayerArgument("target", partial: true, self: true),
				self::item(),
				new IntegerArgument("count", 1, self::MAX_COUNT, optional: true),
				new StringArgument("tags", optional: true, greedy: true)
			])
			->handler($this->give(...));
	}

	/**
	 * Looks an item up by name, including legacy ids. Air is not an item that can be given or cleared.
	 *
	 * @return MappedArgument<Item>
	 */
	public static function item(bool $optional = false) : MappedArgument{
		return new MappedArgument("item", static function(string $name) : Item{
			try{
				$item = StringToItemParser::getInstance()->parse($name) ?? LegacyStringToItemParser::getInstance()->parse($name);
			}catch(LegacyStringToItemParserException){
				throw new ArgumentParseException(KnownTranslationFactory::commands_give_item_notFound($name));
			}
			return $item->isNull() ? throw new ArgumentParseException(KnownTranslationFactory::commands_give_item_notFound($name)) : $item;
		}, Item::class, $optional);
	}

	private function give(CommandSender $sender, Player $target, Item $item, ?int $count = null, ?string $tags = null) : void{
		if(!$this->canTarget($sender, $target, DefaultPermissionNames::COMMAND_GIVE_SELF, DefaultPermissionNames::COMMAND_GIVE_OTHER)){
			return;
		}

		$item->setCount($count ?? $item->getMaxStackSize());
		if($tags !== null){
			try{
				$item->setNamedTag(JsonNbtParser::parseJson($tags));
			}catch(NbtException $e){
				$sender->sendMessage(KnownTranslationFactory::commands_give_tagError($e->getMessage()));
				return;
			}
		}

		$inventory = $target->getInventory();
		if(!$inventory->canAddItem($item)){
			$sender->sendMessage(KnownTranslationFactory::quark_command_give_noSpace($item->getName(), $target->getName())->prefix(TextFormat::RED));
			return;
		}
		$inventory->addItem($item);

		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_give_success($item->getName(), (string) $item->getCount(), $target->getName()));
	}
}
