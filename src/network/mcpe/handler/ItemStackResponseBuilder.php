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
use quark\inventory\Inventory;
use quark\item\Durable;
use quark\network\mcpe\InventoryManager;
use quark\network\mcpe\protocol\Packets;
use quark\utils\AssumptionFailedError;

/**
 * @phpstan-import-type ItemStackResponseInfo from Packets
 */
final class ItemStackResponseBuilder{
	public const RESULT_OK = 0;
	public const RESULT_ERROR = 1;

	/**
	 * @var int[][]
	 * @phpstan-var array<int, array<int, int>>
	 */
	private array $changedSlots = [];

	public function __construct(
		private int $requestId,
		private InventoryManager $inventoryManager
	){}

	public function addSlot(int $containerInterfaceId, int $slotId) : void{
		$this->changedSlots[$containerInterfaceId][$slotId] = $slotId;
	}

	/**
	 * @phpstan-return array{Inventory, int}
	 */
	private function getInventoryAndSlot(int $containerInterfaceId, int $slotId) : ?array{
		[$windowId, $slotId] = ItemStackContainerIdTranslator::translate($containerInterfaceId, $this->inventoryManager->getCurrentWindowId(), $slotId);
		$windowAndSlot = $this->inventoryManager->locateWindowAndSlot($windowId, $slotId);
		if($windowAndSlot === null){
			return null;
		}
		[$inventory, $slot] = $windowAndSlot;
		if(!$inventory->slotExists($slot)){
			return null;
		}

		return [$inventory, $slot];
	}

	/**
	 * @phpstan-return ItemStackResponseInfo
	 */
	public static function error(int $requestId) : array{
		return ["result" => self::RESULT_ERROR, "client_request_id" => $requestId, "containers" => null];
	}

	/**
	 * @phpstan-return ItemStackResponseInfo
	 */
	public function build() : array{
		$responseInfosByContainer = [];
		foreach($this->changedSlots as $containerInterfaceId => $slotIds){
			if($containerInterfaceId === ContainerUIIds::CREATED_OUTPUT){
				continue;
			}
			foreach($slotIds as $slotId){
				$inventoryAndSlot = $this->getInventoryAndSlot($containerInterfaceId, $slotId);
				if($inventoryAndSlot === null){
					//a plugin may have closed the inventory during an event, or the slot may have been invalid
					continue;
				}
				[$inventory, $slot] = $inventoryAndSlot;

				$itemStackInfo = $this->inventoryManager->getItemStackInfo($inventory, $slot);
				if($itemStackInfo === null){
					throw new AssumptionFailedError("ItemStackInfo should never be null for an open inventory");
				}
				$item = $inventory->getItem($slot);

				$responseInfosByContainer[$containerInterfaceId][] = [
					"requested_slot" => $slotId,
					"slot" => $slotId,
					"amount" => $item->getCount(),
					"item_stack_net_id" => $itemStackInfo->getStackId(),
					"custom_name" => ["unredacted" => $item->getCustomName(), "redacted" => $item->getCustomName()],
					"durability_correction" => $item instanceof Durable ? $item->getDamage() : 0,
				];
			}
		}

		$responseContainerInfos = [];
		foreach($responseInfosByContainer as $containerInterfaceId => $responseInfos){
			$responseContainerInfos[] = ["full_container_name" => ["container_name" => $containerInterfaceId, "dynamic_id" => null], "slots" => $responseInfos];
		}

		return ["result" => self::RESULT_OK, "client_request_id" => $this->requestId, "containers" => $responseContainerInfos];
	}
}
