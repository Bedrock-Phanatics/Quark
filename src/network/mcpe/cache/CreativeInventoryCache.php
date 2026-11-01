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

namespace quark\network\mcpe\cache;

use quark\inventory\CreativeCategory;
use quark\inventory\CreativeInventory;
use quark\lang\Translatable;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\EncodedPacket;
use quark\network\mcpe\protocol\Fields;
use quark\network\mcpe\protocol\packet\CreativeContentPacket;
use quark\network\mcpe\protocol\types\CreativeGroup;
use quark\network\mcpe\protocol\types\CreativeItemEntryPayload;
use quark\network\mcpe\protocol\types\inventory\ItemStack;
use quark\utils\SingletonTrait;
use function count;
use function is_string;
use function spl_object_id;
use const PHP_INT_MIN;

final class CreativeInventoryCache{
	use SingletonTrait;

	/** Encoded packets kept per inventory, one per language and protocol in practice. */
	private const MAX_ENCODED_VARIANTS = 16;

	/**
	 * @var CreativeInventoryCacheEntry[]
	 * @phpstan-var array<int, CreativeInventoryCacheEntry>
	 */
	private array $caches = [];

	private function getCacheEntry(CreativeInventory $inventory) : CreativeInventoryCacheEntry{
		$id = spl_object_id($inventory);
		if(!isset($this->caches[$id])){
			$inventory->getDestructorCallbacks()->add(function() use ($id) : void{
				unset($this->caches[$id]);
			});
			$inventory->getContentChangedCallbacks()->add(function() use ($id) : void{
				unset($this->caches[$id]);
			});
			$this->caches[$id] = $this->buildCacheEntry($inventory);
		}
		return $this->caches[$id];
	}

	/**
	 * Rebuild the cache for the given inventory.
	 */
	private function buildCacheEntry(CreativeInventory $inventory) : CreativeInventoryCacheEntry{
		$categories = [];
		$groups = [];

		$typeConverter = TypeConverter::getInstance();

		$nextIndex = 0;
		$groupIndexes = [];
		$itemGroupIndexes = [];

		foreach($inventory->getAllEntries() as $k => $entry){
			$group = $entry->getGroup();
			$category = $entry->getCategory();
			if($group === null){
				$groupId = PHP_INT_MIN;
			}else{
				$groupId = spl_object_id($group);
				unset($groupIndexes[$category->name][PHP_INT_MIN]); //start a new anonymous group for this category
			}

			//group object may be reused by multiple categories
			if(!isset($groupIndexes[$category->name][$groupId])){
				$groupIndexes[$category->name][$groupId] = $nextIndex++;
				$categories[] = $category;
				$groups[] = $group;
			}
			$itemGroupIndexes[$k] = $groupIndexes[$category->name][$groupId];
		}

		//creative inventory may have holes if items were unregistered - ensure network IDs used are always consistent
		$items = [];
		foreach($inventory->getAllEntries() as $k => $entry){
			$items[] = new CreativeItemEntryPayload(
				creativeNetId: $k + 1, //0 is not a valid net ID, the client can't request it back
				itemInstance: Fields::itemInstance($typeConverter->coreItemStackToNet($entry->getItem())),
				groupIndex: $itemGroupIndexes[$k],
			);
		}

		return new CreativeInventoryCacheEntry($categories, $groups, $items);
	}

	/**
	 * Builds the cache entry ahead of time, so the first player to need it doesn't wait for it.
	 */
	public function prepare(CreativeInventory $inventory) : void{
		$this->getCacheEntry($inventory);
	}

	public function buildPacket(CreativeInventory $inventory, NetworkSession $session) : EncodedPacket{
		$player = $session->getPlayer() ?? throw new \LogicException("Cannot prepare creative data for a session without a player");
		$language = $player->getLanguage();
		$forceLanguage = $player->getServer()->isLanguageForced();
		$typeConverter = $session->getTypeConverter();
		$cachedEntry = $this->getCacheEntry($inventory);
		//group names are all that differs between players: they come from the language, the rest from the converter
		$key = spl_object_id($typeConverter) . ($forceLanguage ? ":forced:" : ":client:") . $language->getLang();
		if(isset($cachedEntry->encoded[$key])){
			return $cachedEntry->encoded[$key];
		}
		$translate = function(Translatable|string $translatable) use ($session, $language, $forceLanguage) : string{
			if(is_string($translatable)){
				$message = $translatable;
			}elseif(!$forceLanguage){
				[$message,] = $session->prepareClientTranslatableMessage($translatable);
			}else{
				$message = $language->translate($translatable);
			}
			return $message;
		};

		$groupEntries = [];
		foreach($cachedEntry->categories as $index => $category){
			$group = $cachedEntry->groups[$index];
			$categoryId = match ($category) {
				CreativeCategory::CONSTRUCTION => CreativeContentPacket::CATEGORY_CONSTRUCTION,
				CreativeCategory::NATURE => CreativeContentPacket::CATEGORY_NATURE,
				CreativeCategory::EQUIPMENT => CreativeContentPacket::CATEGORY_EQUIPMENT,
				CreativeCategory::ITEMS => CreativeContentPacket::CATEGORY_ITEMS
			};
			if($group === null){
				$groupEntries[] = new CreativeGroup(creativeCategory: $categoryId, name: "", groupIconItem: Fields::itemInstance(ItemStack::null()));
			}else{
				$groupIcon = $group->getIcon();
				//TODO: HACK! In 1.21.60, Workaround glitchy behaviour when an item is used as an icon for a group it
				//doesn't belong to. Without this hack, both instances of the item will show a +, but neither of them
				//will actually expand the group work correctly.
				$groupIcon->getNamedTag()->setInt("___GroupBugWorkaround___", $index);
				$groupName = $group->getName();
				$groupEntries[] = new CreativeGroup(
					creativeCategory: $categoryId,
					name: $translate($groupName),
					groupIconItem: Fields::itemInstance($typeConverter->coreItemStackToNet($groupIcon)),
				);
			}
		}

		//1700+ items cost a few ms to encode, so players who would get the same packet share the encoded one
		$encoded = EncodedPacket::of(new CreativeContentPacket(groups: $groupEntries, entries: $cachedEntry->items));
		if(count($cachedEntry->encoded) < self::MAX_ENCODED_VARIANTS){
			$cachedEntry->encoded[$key] = $encoded;
		}
		return $encoded;
	}
}
