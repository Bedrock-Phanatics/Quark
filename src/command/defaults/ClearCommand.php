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
use quark\inventory\Inventory;
use quark\item\Item;
use quark\item\LegacyStringToItemParser;
use quark\item\LegacyStringToItemParserException;
use quark\item\StringToItemParser;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\utils\TextFormat;
use function count;
use function min;

class ClearCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"clear",
			KnownTranslationFactory::quark_command_clear_description(),
			KnownTranslationFactory::quark_command_clear_usage()
		);
		$this->setPermissions([DefaultPermissionNames::COMMAND_CLEAR_SELF, DefaultPermissionNames::COMMAND_CLEAR_OTHER]);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if(count($args) > 3){
			throw new InvalidCommandSyntaxException();
		}

		$target = $this->fetchPermittedPlayerTarget($sender, $args[0] ?? null, DefaultPermissionNames::COMMAND_CLEAR_SELF, DefaultPermissionNames::COMMAND_CLEAR_OTHER);
		if($target === null){
			return true;
		}

		$targetItem = null;
		$maxCount = -1;
		if(isset($args[1])){
			try{
				$targetItem = StringToItemParser::getInstance()->parse($args[1]) ?? LegacyStringToItemParser::getInstance()->parse($args[1]);

				if(isset($args[2])){
					$targetItem->setCount($maxCount = $this->getInteger($sender, $args[2], -1));
				}
			}catch(LegacyStringToItemParserException $e){
				//vanilla checks this at argument parsing layer, can't come up with a better alternative
				$sender->sendMessage(KnownTranslationFactory::commands_give_item_notFound($args[1])->prefix(TextFormat::RED));
				return true;
			}
		}

		/**
		 * @var Inventory[] $inventories - This is the order that vanilla would clear items in.
		 */
		$inventories = [
			$target->getInventory(),
			$target->getCursorInventory(),
			$target->getArmorInventory(),
			$target->getOffHandInventory()
		];

		// Checking player's inventory for all the items matching the criteria
		if($targetItem !== null && $maxCount === 0){
			$count = $this->countItems($inventories, $targetItem);
			if($count > 0){
				$sender->sendMessage(KnownTranslationFactory::commands_clear_testing($target->getName(), (string) $count));
			}else{
				$sender->sendMessage(KnownTranslationFactory::commands_clear_failure_no_items($target->getName())->prefix(TextFormat::RED));
			}

			return true;
		}

		$clearedCount = 0;
		if($targetItem === null){
			// Clear all items from the inventories
			$clearedCount += $this->countItems($inventories, null);
			foreach($inventories as $inventory){
				$inventory->clearAll();
			}
		}else{
			// Clear the item from target's inventory irrelevant of the count
			if($maxCount === -1){
				$clearedCount += $this->countItems($inventories, $targetItem);
				foreach($inventories as $inventory){
					$inventory->remove($targetItem);
				}
			}else{
				// Clear the item from target's inventory up to maxCount
				foreach($inventories as $inventory){
					foreach($inventory->all($targetItem) as $index => $item){
						// The count to reduce from the item and max count
						$reductionCount = min($item->getCount(), $maxCount);
						$item->pop($reductionCount);
						$clearedCount += $reductionCount;
						$inventory->setItem($index, $item);

						$maxCount -= $reductionCount;
						if($maxCount <= 0){
							break 2;
						}
					}
				}
			}
		}

		if($clearedCount > 0){
			Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_clear_success($target->getName(), (string) $clearedCount));
		}else{
			$sender->sendMessage(KnownTranslationFactory::commands_clear_failure_no_items($target->getName())->prefix(TextFormat::RED));
		}

		return true;
	}

	/**
	 * @param Inventory[] $inventories
	 */
	protected function countItems(array $inventories, ?Item $target) : int{
		$count = 0;
		foreach($inventories as $inventory){
			$contents = $target !== null ? $inventory->all($target) : $inventory->getContents();
			foreach($contents as $item){
				$count += $item->getCount();
			}
		}
		return $count;
	}
}
