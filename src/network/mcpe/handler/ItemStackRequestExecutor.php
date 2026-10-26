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

namespace quark\network\mcpe\handler;

use pocketmine\network\mcpe\protocol\types\inventory\ContainerUIIds;
use pocketmine\network\mcpe\protocol\types\inventory\UIInventorySlotOffset;
use quark\block\inventory\EnchantInventory;
use quark\crafting\CraftingGrid;
use quark\crafting\ShapedRecipe;
use quark\inventory\Inventory;
use quark\inventory\transaction\action\CreateItemAction;
use quark\inventory\transaction\action\DestroyItemAction;
use quark\inventory\transaction\action\DropItemAction;
use quark\inventory\transaction\CraftingTransaction;
use quark\inventory\transaction\EnchantingTransaction;
use quark\inventory\transaction\InventoryTransaction;
use quark\inventory\transaction\TransactionBuilder;
use quark\inventory\transaction\TransactionBuilderInventory;
use quark\item\Durable;
use quark\item\Item;
use quark\network\mcpe\cache\CraftingDataCache;
use quark\network\mcpe\InventoryManager;
use quark\network\mcpe\protocol\Packets;
use quark\player\Player;
use quark\utils\AssumptionFailedError;
use function array_key_first;
use function count;
use function spl_object_id;

/**
 * @phpstan-import-type ItemStackRequestPacketData from Packets
 * @phpstan-import-type StackRequestAction from Packets
 * @phpstan-import-type StackRequestSlotInfo from Packets
 */
class ItemStackRequestExecutor{
	private TransactionBuilder $builder;

	/** @phpstan-var list<StackRequestSlotInfo> */
	private array $requestSlotInfos = [];

	private ?InventoryTransaction $specialTransaction = null;

	/** @var Item[] */
	private array $craftingResults = [];

	private ?Item $nextCreatedItem = null;
	private bool $createdItemFromCreativeInventory = false;
	private int $createdItemsTakenCount = 0;

	/**
	 * @phpstan-param ItemStackRequestPacketData $request
	 */
	public function __construct(
		private Player $player,
		private InventoryManager $inventoryManager,
		private array $request
	){
		$this->builder = new TransactionBuilder();
	}

	protected function prettyInventoryAndSlot(Inventory $inventory, int $slot) : string{
		if($inventory instanceof TransactionBuilderInventory){
			$inventory = $inventory->getActualInventory();
		}
		return (new \ReflectionClass($inventory))->getShortName() . "#" . spl_object_id($inventory) . ", slot: $slot";
	}

	/**
	 * @throws ItemStackRequestProcessException
	 */
	private function matchItemStack(Inventory $inventory, int $slotId, int $clientItemStackId) : void{
		$info = $this->inventoryManager->getItemStackInfo($inventory, $slotId);
		if($info === null){
			throw new AssumptionFailedError("The inventory is tracked and the slot is valid, so this should not be null");
		}

		if(!($clientItemStackId < 0 ? $info->getRequestId() === $clientItemStackId : $info->getStackId() === $clientItemStackId)){
			throw new ItemStackRequestProcessException(
				$this->prettyInventoryAndSlot($inventory, $slotId) . ": " .
				"Mismatched expected itemstack, " .
				"client expected: $clientItemStackId, server actual: " . $info->getStackId() . ", last modified by request: " . ($info->getRequestId() ?? "none")
			);
		}
	}

	/**
	 * @phpstan-param StackRequestSlotInfo $info
	 * @phpstan-return array{TransactionBuilderInventory, int}
	 *
	 * @throws ItemStackRequestProcessException
	 */
	protected function getBuilderInventoryAndSlot(array $info) : array{
		$containerId = $info["full_container_name"]["container_name"];
		[$windowId, $slotId] = ItemStackContainerIdTranslator::translate($containerId, $this->inventoryManager->getCurrentWindowId(), $info["slot"]);
		$windowAndSlot = $this->inventoryManager->locateWindowAndSlot($windowId, $slotId);
		if($windowAndSlot === null){
			throw new ItemStackRequestProcessException("No open inventory matches container UI ID: " . $containerId . ", slot ID: " . $info["slot"]);
		}
		[$inventory, $slot] = $windowAndSlot;
		if(!$inventory->slotExists($slot)){
			throw new ItemStackRequestProcessException("No such inventory slot :" . $this->prettyInventoryAndSlot($inventory, $slot));
		}

		if($info["net_id_variant"] !== $this->request["client_request_id"]){ //the itemstack may have been modified by the current request
			$this->matchItemStack($inventory, $slot, $info["net_id_variant"]);
		}

		return [$this->builder->getInventory($inventory), $slot];
	}

	/**
	 * @phpstan-param StackRequestSlotInfo $source
	 * @phpstan-param StackRequestSlotInfo $destination
	 * @throws ItemStackRequestProcessException
	 */
	protected function transferItems(array $source, array $destination, int $count) : void{
		$removed = $this->removeItemFromSlot($source, $count);
		$this->addItemToSlot($destination, $removed, $count);
	}

	/**
	 * Deducts items from an inventory slot, returning a stack containing the removed items.
	 *
	 * @phpstan-param StackRequestSlotInfo $slotInfo
	 * @throws ItemStackRequestProcessException
	 */
	protected function removeItemFromSlot(array $slotInfo, int $count) : Item{
		if($slotInfo["full_container_name"]["container_name"] === ContainerUIIds::CREATED_OUTPUT && $slotInfo["slot"] === UIInventorySlotOffset::CREATED_ITEM_OUTPUT){
			//special case for the "created item" output slot
			//TODO: do we need to send a response for this slot info?
			return $this->takeCreatedItem($count);
		}
		$this->requestSlotInfos[] = $slotInfo;
		[$inventory, $slot] = $this->getBuilderInventoryAndSlot($slotInfo);
		if($count < 1){
			//this should be impossible at the protocol level, but in case of buggy core code this will prevent exploits
			throw new ItemStackRequestProcessException($this->prettyInventoryAndSlot($inventory, $slot) . ": Cannot take less than 1 items from a stack");
		}

		$existingItem = $inventory->getItem($slot);
		if($existingItem->getCount() < $count){
			throw new ItemStackRequestProcessException($this->prettyInventoryAndSlot($inventory, $slot) . ": Cannot take $count items from a stack of " . $existingItem->getCount());
		}

		$removed = $existingItem->pop($count);
		$inventory->setItem($slot, $existingItem);

		return $removed;
	}

	/**
	 * Adds items to the target slot, if they are stackable.
	 *
	 * @phpstan-param StackRequestSlotInfo $slotInfo
	 * @throws ItemStackRequestProcessException
	 */
	protected function addItemToSlot(array $slotInfo, Item $item, int $count) : void{
		$this->requestSlotInfos[] = $slotInfo;
		[$inventory, $slot] = $this->getBuilderInventoryAndSlot($slotInfo);
		if($count < 1){
			//this should be impossible at the protocol level, but in case of buggy core code this will prevent exploits
			throw new ItemStackRequestProcessException($this->prettyInventoryAndSlot($inventory, $slot) . ": Cannot take less than 1 items from a stack");
		}

		$existingItem = $inventory->getItem($slot);
		if(!$existingItem->isNull() && !$existingItem->canStackWith($item)){
			throw new ItemStackRequestProcessException($this->prettyInventoryAndSlot($inventory, $slot) . ": Can only add items to an empty slot, or a slot containing the same item");
		}

		//we can't use the existing item here; it may be an empty stack
		$newItem = clone $item;
		$newItem->setCount($existingItem->getCount() + $count);
		$inventory->setItem($slot, $newItem);
	}

	protected function dropItem(Item $item, int $count) : void{
		if($count < 1){
			throw new ItemStackRequestProcessException("Cannot drop less than 1 of an item");
		}
		$this->builder->addAction(new DropItemAction((clone $item)->setCount($count)));
	}

	/**
	 * @throws ItemStackRequestProcessException
	 */
	protected function setNextCreatedItem(?Item $item, bool $creative = false) : void{
		if($item !== null && $item->isNull()){
			$item = null;
		}
		if($this->nextCreatedItem !== null){
			//while this is more complicated than simply adding the action when the item is taken, this ensures that
			//plugins can tell the difference between 1 item that got split into 2 slots, vs 2 separate items.
			if($this->createdItemFromCreativeInventory && $this->createdItemsTakenCount > 0){
				$this->nextCreatedItem->setCount($this->createdItemsTakenCount);
				$this->builder->addAction(new CreateItemAction($this->nextCreatedItem));
			}elseif($this->createdItemsTakenCount < $this->nextCreatedItem->getCount()){
				throw new ItemStackRequestProcessException("Not all of the previous created item was taken");
			}
		}
		$this->nextCreatedItem = $item;
		$this->createdItemFromCreativeInventory = $creative;
		$this->createdItemsTakenCount = 0;
	}

	/**
	 * @throws ItemStackRequestProcessException
	 */
	protected function beginCrafting(int $recipeId, int $repetitions) : void{
		if($this->specialTransaction !== null){
			throw new ItemStackRequestProcessException("Another special transaction is already in progress");
		}
		if($repetitions < 1){
			throw new ItemStackRequestProcessException("Cannot craft a recipe less than 1 time");
		}
		if($repetitions > 256){
			//TODO: we can probably lower this limit to 64, but I'm unsure if there are cases where the client may
			//request more than 64 repetitions of a recipe.
			//It's already hard-limited to 256 repetitions in the protocol, so this is just a sanity check.
			throw new ItemStackRequestProcessException("Cannot craft a recipe more than 256 times");
		}
		$currentWindow = $this->player->getCurrentWindow();
		if($currentWindow !== null && !$currentWindow instanceof CraftingGrid){
			throw new ItemStackRequestProcessException("Crafting recipes cannot be used while another workstation is open");
		}
		$craftingGrid = $currentWindow ?? $this->player->getCraftingGrid();

		$craftingManager = $this->player->getServer()->getCraftingManager();
		$recipeIndex = $recipeId - CraftingDataCache::RECIPE_ID_OFFSET;
		$recipe = $craftingManager->getCraftingRecipeFromIndex($recipeIndex);
		if($recipe === null){
			throw new ItemStackRequestProcessException("No such crafting recipe index: $recipeIndex");
		}
		$gridWidth = $craftingGrid->getGridWidth();
		if(
			count($recipe->getIngredientList()) > $gridWidth ** 2 ||
			($recipe instanceof ShapedRecipe && ($recipe->getWidth() > $gridWidth || $recipe->getHeight() > $gridWidth))
		){
			throw new ItemStackRequestProcessException("Recipe does not fit in the active {$gridWidth}x{$gridWidth} crafting grid");
		}

		$this->specialTransaction = new CraftingTransaction($this->player, $craftingManager, [], $recipe, $repetitions, $craftingGrid);

		$craftingResults = $recipe->getResultsFor($craftingGrid);
		foreach($craftingResults as $k => $craftingResult){
			$craftingResult->setCount($craftingResult->getCount() * $repetitions);
			$this->craftingResults[$k] = $craftingResult;
		}
		if(count($this->craftingResults) === 1){
			//for multi-output recipes, later actions will tell us which result to create and when
			$this->setNextCreatedItem($this->craftingResults[array_key_first($this->craftingResults)]);
		}
	}

	/**
	 * @throws ItemStackRequestProcessException
	 */
	protected function takeCreatedItem(int $count) : Item{
		if($count < 1){
			//this should be impossible at the protocol level, but in case of buggy core code this will prevent exploits
			throw new ItemStackRequestProcessException("Cannot take less than 1 created item");
		}
		$createdItem = $this->nextCreatedItem;
		if($createdItem === null){
			throw new ItemStackRequestProcessException("No created item is waiting to be taken");
		}

		if(!$this->createdItemFromCreativeInventory){
			$availableCount = $createdItem->getCount() - $this->createdItemsTakenCount;
			if($count > $availableCount){
				throw new ItemStackRequestProcessException("Not enough created items available to be taken (have $availableCount, tried to take $count)");
			}
		}

		$this->createdItemsTakenCount += $count;
		$takenItem = clone $createdItem;
		$takenItem->setCount($count);
		if(!$this->createdItemFromCreativeInventory && $this->createdItemsTakenCount >= $createdItem->getCount()){
			$this->setNextCreatedItem(null);
		}
		return $takenItem;
	}

	/**
	 * @throws ItemStackRequestProcessException
	 */
	private function assertDoingCrafting() : void{
		if(!$this->specialTransaction instanceof CraftingTransaction && !$this->specialTransaction instanceof EnchantingTransaction){
			if($this->specialTransaction === null){
				throw new ItemStackRequestProcessException("Expected CraftRecipe or CraftRecipeAuto action to precede this action");
			}else{
				throw new ItemStackRequestProcessException("A different special transaction is already in progress");
			}
		}
	}

	/**
	 * @phpstan-param StackRequestAction $action
	 * @throws ItemStackRequestProcessException
	 */
	protected function processItemStackRequestAction(array $action) : void{
		if(isset($action["take"])){
			$this->transferItems($action["take"]["source"], $action["take"]["destination"], $action["take"]["amount"]);
		}elseif(isset($action["place"])){
			$this->transferItems($action["place"]["source"], $action["place"]["destination"], $action["place"]["amount"]);
		}elseif(isset($action["swap"])){
			$swap = $action["swap"];
			$this->requestSlotInfos[] = $swap["source"];
			$this->requestSlotInfos[] = $swap["destination"];

			[$inventory1, $slot1] = $this->getBuilderInventoryAndSlot($swap["source"]);
			[$inventory2, $slot2] = $this->getBuilderInventoryAndSlot($swap["destination"]);

			$item1 = $inventory1->getItem($slot1);
			$item2 = $inventory2->getItem($slot2);
			$inventory1->setItem($slot1, $item2);
			$inventory2->setItem($slot2, $item1);
		}elseif(isset($action["drop"])){
			//TODO: this action has a "randomly" field, I have no idea what it's used for
			$dropped = $this->removeItemFromSlot($action["drop"]["source"], $action["drop"]["amount"]);
			$this->builder->addAction(new DropItemAction($dropped));

		}elseif(isset($action["destroy"])){
			$destroyed = $this->removeItemFromSlot($action["destroy"]["source"], $action["destroy"]["amount"]);
			$this->builder->addAction(new DestroyItemAction($destroyed));

		}elseif(isset($action["craft_creative"])){
			$creativeItemId = $action["craft_creative"]["creative_item_net_id"];
			$item = $this->player->getCreativeInventory()->getItem($creativeItemId);
			if($item === null){
				throw new ItemStackRequestProcessException("No such creative item index: " . $creativeItemId);
			}

			$this->setNextCreatedItem($item, true);
		}elseif(isset($action["craft_recipe"])){
			$craft = $action["craft_recipe"];
			$window = $this->player->getCurrentWindow();
			if($window instanceof EnchantInventory){
				if($this->specialTransaction !== null){
					throw new ItemStackRequestProcessException("Another special transaction is already in progress");
				}
				if($craft["number_of_requested_crafts"] !== 1){
					throw new ItemStackRequestProcessException("Enchanting operations must have exactly 1 repetition");
				}
				$optionId = $this->inventoryManager->getEnchantingTableOptionIndex($craft["recipe_net_id"]);
				if($optionId === null || ($option = $window->getOption($optionId)) === null || ($output = $window->getOutput($optionId)) === null){
					throw new ItemStackRequestProcessException("No such enchanting option");
				}
				$this->specialTransaction = new EnchantingTransaction($this->player, $option, $optionId + 1, $window);
				$this->setNextCreatedItem($output);
			}else{
				$this->beginCrafting($craft["recipe_net_id"], $craft["number_of_requested_crafts"]);
			}
		}elseif(isset($action["craft_recipe_auto"])){
			$this->beginCrafting($action["craft_recipe_auto"]["recipe_net_id"], $action["craft_recipe_auto"]["number_of_requested_crafts"]);
		}elseif(isset($action["consume"])){
			$this->assertDoingCrafting();
			$this->removeItemFromSlot($action["consume"]["source"], $action["consume"]["amount"]); //output discarded - we allow CraftingTransaction to verify the balance

		}elseif(isset($action["create"])){
			$this->assertDoingCrafting();

			$resultIndex = $action["create"]["results_index"];
			$nextResultItem = $this->craftingResults[$resultIndex] ?? null;
			if($nextResultItem === null){
				throw new ItemStackRequestProcessException("No such crafting result index: " . $resultIndex);
			}
			$this->setNextCreatedItem($nextResultItem);
		}elseif(isset($action["craft_results"])){
			//no obvious use
		}elseif(isset($action["mine_block"])){
			$mine = $action["mine_block"];
			$slot = $mine["slot"];
			$this->requestSlotInfos[] = [
				"full_container_name" => ["container_name" => ContainerUIIds::HOTBAR, "dynamic_id" => null],
				"slot" => $slot,
				"net_id_variant" => $mine["net_id_variant"],
			];
			$inventory = $this->player->getInventory();
			$usedItem = $inventory->slotExists($slot) ? $inventory->getItem($slot) : null;
			$predictedDamage = $mine["predicted_durability"];
			if($usedItem instanceof Durable && $predictedDamage >= 0 && $predictedDamage <= $usedItem->getMaxDurability()){
				$usedItem->setDamage($predictedDamage);
				$this->inventoryManager->addPredictedSlotChange($inventory, $slot, $usedItem);
			}
		}else{
			throw new ItemStackRequestProcessException("Unhandled item stack request action");
		}
	}

	/**
	 * @throws ItemStackRequestProcessException
	 */
	public function generateInventoryTransaction() : ?InventoryTransaction{
		foreach($this->request["actions"] as $k => $action){
			try{
				$this->processItemStackRequestAction($action);
			}catch(ItemStackRequestProcessException $e){
				throw new ItemStackRequestProcessException("Error processing action $k (" . array_key_first($action) . "): " . $e->getMessage(), 0, $e);
			}
		}
		$this->setNextCreatedItem(null);
		$inventoryActions = $this->builder->generateActions();
		if(count($inventoryActions) === 0){
			return null;
		}

		$transaction = $this->specialTransaction ?? new InventoryTransaction($this->player);
		foreach($inventoryActions as $action){
			$transaction->addAction($action);
		}

		return $transaction;
	}

	public function getItemStackResponseBuilder() : ItemStackResponseBuilder{
		$builder = new ItemStackResponseBuilder($this->request["client_request_id"], $this->inventoryManager);
		foreach($this->requestSlotInfos as $requestInfo){
			$builder->addSlot($requestInfo["full_container_name"]["container_name"], $requestInfo["slot"]);
		}

		return $builder;
	}
}
