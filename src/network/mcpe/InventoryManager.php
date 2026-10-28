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

namespace quark\network\mcpe;

use Bedwire\PacketId;
use pocketmine\network\mcpe\protocol\InventoryContentPacket;
use pocketmine\network\mcpe\protocol\InventorySlotPacket;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\inventory\NetworkInventoryAction;
use pocketmine\network\mcpe\protocol\types\inventory\UIInventorySlotOffset;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use pocketmine\utils\Binary;
use quark\block\inventory\AnvilInventory;
use quark\block\inventory\BlockInventory;
use quark\block\inventory\BrewingStandInventory;
use quark\block\inventory\CartographyTableInventory;
use quark\block\inventory\CraftingTableInventory;
use quark\block\inventory\EnchantInventory;
use quark\block\inventory\FurnaceInventory;
use quark\block\inventory\HopperInventory;
use quark\block\inventory\LoomInventory;
use quark\block\inventory\SmithingTableInventory;
use quark\block\inventory\StonecutterInventory;
use quark\crafting\FurnaceType;
use quark\data\bedrock\EnchantmentIdMap;
use quark\inventory\BlockInventoryWindow;
use quark\inventory\Inventory;
use quark\inventory\transaction\action\SlotChangeAction;
use quark\inventory\transaction\InventoryTransaction;
use quark\item\enchantment\EnchantingOption;
use quark\item\Item;
use quark\network\mcpe\cache\CreativeInventoryCache;
use quark\network\mcpe\protocol\ArrayPacket;
use quark\network\mcpe\protocol\Fields;
use quark\network\mcpe\protocol\Packet;
use quark\network\mcpe\protocol\packet\ContainerOpenPacket;
use quark\network\mcpe\protocol\Packets;
use quark\network\mcpe\protocol\types\InventoryAction;
use quark\network\PacketHandlingException;
use quark\player\Player;
use quark\utils\AssumptionFailedError;
use quark\utils\ObjectSet;
use function array_fill;
use function array_keys;
use function array_search;
use function count;
use function get_class;
use function implode;
use function is_int;
use function max;
use function spl_object_id;

/**
 * @phpstan-type ContainerOpenClosure \Closure(int $id, Inventory $inventory) : (list<Packet>|null)
 */
class InventoryManager{
	/**
	 * @var InventoryManagerEntry[] spl_object_id(Inventory) => InventoryManagerEntry
	 * @phpstan-var array<int, InventoryManagerEntry>
	 */
	private array $inventories = [];

	/**
	 * @var Inventory[] network window ID => Inventory
	 * @phpstan-var array<int, Inventory>
	 */
	private array $networkIdToInventoryMap = [];
	/**
	 * @var ComplexInventoryMapEntry[] net slot ID => ComplexWindowMapEntry
	 * @phpstan-var array<int, ComplexInventoryMapEntry>
	 */
	private array $complexSlotToInventoryMap = [];

	private int $lastInventoryNetworkId = ContainerIds::FIRST;
	private int $currentWindowType = WindowTypes::CONTAINER;

	private int $clientSelectedHotbarSlot = -1;

	/** @phpstan-var ObjectSet<ContainerOpenClosure> */
	private ObjectSet $containerOpenCallbacks;

	private ?int $pendingCloseWindowId = null;
	/** @phpstan-var \Closure() : void */
	private ?\Closure $pendingOpenWindowCallback = null;

	private int $nextItemStackId = 1;
	private ?int $currentItemStackRequestId = null;

	private bool $fullSyncRequested = false;

	/** @var int[] network recipe ID => enchanting table option index */
	private array $enchantingTableOptions = [];
	//TODO: this should be based on the total number of crafting recipes - if there are ever 100k recipes, this will
	//conflict with regular recipes
	private int $nextEnchantingTableOptionId = 100000;

	public function __construct(
		private Player $player,
		private NetworkSession $session
	){
		$this->containerOpenCallbacks = new ObjectSet();
		$this->containerOpenCallbacks->add(self::createContainerOpen(...));

		$this->add(ContainerIds::INVENTORY, $this->player->getInventory());
		$this->add(ContainerIds::OFFHAND, $this->player->getOffHandInventory());
		$this->add(ContainerIds::ARMOR, $this->player->getArmorInventory());
		$this->addComplex(UIInventorySlotOffset::CURSOR, $this->player->getCursorInventory());
		$this->addComplex(UIInventorySlotOffset::CRAFTING2X2_INPUT, $this->player->getCraftingGrid());

		$this->player->getInventory()->getHeldItemIndexChangeListeners()->add($this->syncSelectedHotbarSlot(...));
	}

	private function associateIdWithInventory(int $id, Inventory $inventory) : void{
		$this->networkIdToInventoryMap[$id] = $inventory;
	}

	private function getNewWindowId() : int{
		$this->lastInventoryNetworkId = max(ContainerIds::FIRST, ($this->lastInventoryNetworkId + 1) % ContainerIds::LAST);
		return $this->lastInventoryNetworkId;
	}

	private function add(int $id, Inventory $inventory) : void{
		if(isset($this->inventories[spl_object_id($inventory)])){
			throw new \InvalidArgumentException("Inventory " . get_class($inventory) . " is already tracked");
		}
		$this->inventories[spl_object_id($inventory)] = new InventoryManagerEntry($inventory);
		$this->associateIdWithInventory($id, $inventory);
	}

	private function addDynamic(Inventory $inventory) : int{
		$id = $this->getNewWindowId();
		$this->add($id, $inventory);
		return $id;
	}

	/**
	 * @param int[]|int $slotMap
	 * @phpstan-param array<int, int>|int $slotMap
	 */
	private function addComplex(array|int $slotMap, Inventory $inventory) : void{
		if(isset($this->inventories[spl_object_id($inventory)])){
			throw new \InvalidArgumentException("Inventory " . get_class($inventory) . " is already tracked");
		}
		$complexSlotMap = new ComplexInventoryMapEntry($inventory, is_int($slotMap) ? [$slotMap => 0] : $slotMap);
		$this->inventories[spl_object_id($inventory)] = new InventoryManagerEntry(
			$inventory,
			$complexSlotMap
		);
		foreach($complexSlotMap->getSlotMap() as $netSlot => $coreSlot){
			$this->complexSlotToInventoryMap[$netSlot] = $complexSlotMap;
		}
	}

	/**
	 * @param int[]|int $slotMap
	 * @phpstan-param array<int, int>|int $slotMap
	 */
	private function addComplexDynamic(array|int $slotMap, Inventory $inventory) : int{
		$this->addComplex($slotMap, $inventory);
		$id = $this->getNewWindowId();
		$this->associateIdWithInventory($id, $inventory);
		return $id;
	}

	private function remove(int $id) : void{
		$inventory = $this->networkIdToInventoryMap[$id];
		unset($this->networkIdToInventoryMap[$id]);
		if($this->getWindowId($inventory) === null){
			unset($this->inventories[spl_object_id($inventory)]);
			foreach($this->complexSlotToInventoryMap as $netSlot => $entry){
				if($entry->getInventory() === $inventory){
					unset($this->complexSlotToInventoryMap[$netSlot]);
				}
			}
		}
	}

	public function getWindowId(Inventory $inventory) : ?int{
		return ($id = array_search($inventory, $this->networkIdToInventoryMap, true)) !== false ? $id : null;
	}

	public function getCurrentWindowId() : int{
		return $this->lastInventoryNetworkId;
	}

	/**
	 * @phpstan-return array{Inventory, int}|null
	 */
	public function locateWindowAndSlot(int $windowId, int $netSlotId) : ?array{
		if($windowId === ContainerIds::UI){
			$entry = $this->complexSlotToInventoryMap[$netSlotId] ?? null;
			if($entry === null){
				return null;
			}
			$inventory = $entry->getInventory();
			$coreSlotId = $entry->mapNetToCore($netSlotId);
			return $coreSlotId !== null && $inventory->slotExists($coreSlotId) ? [$inventory, $coreSlotId] : null;
		}
		$inventory = $this->networkIdToInventoryMap[$windowId] ?? null;
		if($inventory !== null && $inventory->slotExists($netSlotId)){
			return [$inventory, $netSlotId];
		}
		return null;
	}

	private function addPredictedSlotChangeInternal(Inventory $inventory, int $slot, ItemStack $item) : void{
		$this->inventories[spl_object_id($inventory)]->predictions[$slot] = $item;
	}

	public function addPredictedSlotChange(Inventory $inventory, int $slot, Item $item) : void{
		$typeConverter = $this->session->getTypeConverter();
		$itemStack = $typeConverter->coreItemStackToNet($item);
		$this->addPredictedSlotChangeInternal($inventory, $slot, $itemStack);
	}

	public function addTransactionPredictedSlotChanges(InventoryTransaction $tx) : void{
		foreach($tx->getActions() as $action){
			if($action instanceof SlotChangeAction){
				//TODO: ItemStackRequestExecutor can probably build these predictions with much lower overhead
				$this->addPredictedSlotChange(
					$action->getInventory(),
					$action->getSlot(),
					$action->getTargetItem()
				);
			}
		}
	}

	/**
	 * @phpstan-param list<InventoryAction> $networkInventoryActions
	 * @throws PacketHandlingException
	 */
	public function addRawPredictedSlotChanges(array $networkInventoryActions) : void{
		foreach($networkInventoryActions as $action){
			if($action->source->sourceType !== NetworkInventoryAction::SOURCE_CONTAINER){
				continue;
			}
			$windowId = $action->source->containerId;
			if($windowId === null){
				throw new PacketHandlingException("Window ID should always be set for SOURCE_CONTAINER");
			}

			//legacy transactions should not modify or predict anything other than these inventories, since these are
			//the only ones accessible when not in-game (ItemStackRequest is used for everything else)
			if(match($windowId){
				ContainerIds::INVENTORY, ContainerIds::OFFHAND, ContainerIds::ARMOR => false,
				default => true
			}){
				throw new PacketHandlingException("Legacy transactions cannot predict changes to inventory with ID " . $windowId);
			}
			$info = $this->locateWindowAndSlot($windowId, $action->slot);
			if($info === null){
				continue;
			}

			[$inventory, $slot] = $info;
			$this->addPredictedSlotChangeInternal($inventory, $slot, Fields::toItemStack($action->toItem));
		}
	}

	public function setCurrentItemStackRequestId(?int $id) : void{
		$this->currentItemStackRequestId = $id;
	}

	/**
	 * When the server initiates a window close, it does so by sending a ContainerClose to the client, which causes the
	 * client to behave as if it initiated the close itself. It responds by sending a ContainerClose back to the server,
	 * which the server is then expected to respond to.
	 *
	 * Sending the client a new window before sending this final response creates buggy behaviour on the client, which
	 * is problematic when switching windows. Therefore, we defer sending any new windows until after the client
	 * responds to our window close instruction, so that we can complete the window handshake correctly.
	 *
	 * This is a pile of complicated garbage that only exists because Mojang overengineered the process of opening and
	 * closing inventory windows.
	 *
	 * @phpstan-param \Closure() : void $func
	 */
	private function openWindowDeferred(\Closure $func) : void{
		if($this->pendingCloseWindowId !== null){
			$this->session->getLogger()->debug("Deferring opening of new window, waiting for close ack of window $this->pendingCloseWindowId");
			$this->pendingOpenWindowCallback = $func;
		}else{
			$func();
		}
	}

	/**
	 * @return int[]|null
	 * @phpstan-return array<int, int>|null
	 */
	private function createComplexSlotMapping(Inventory $inventory) : ?array{
		//TODO: make this dynamic so plugins can add mappings for stuff not implemented by PM
		return match(true){
			$inventory instanceof AnvilInventory => UIInventorySlotOffset::ANVIL,
			$inventory instanceof EnchantInventory => UIInventorySlotOffset::ENCHANTING_TABLE,
			$inventory instanceof LoomInventory => UIInventorySlotOffset::LOOM,
			$inventory instanceof StonecutterInventory => [UIInventorySlotOffset::STONE_CUTTER_INPUT => StonecutterInventory::SLOT_INPUT],
			$inventory instanceof CraftingTableInventory => UIInventorySlotOffset::CRAFTING3X3_INPUT,
			$inventory instanceof CartographyTableInventory => UIInventorySlotOffset::CARTOGRAPHY_TABLE,
			$inventory instanceof SmithingTableInventory => UIInventorySlotOffset::SMITHING_TABLE,
			default => null,
		};
	}

	public function onCurrentWindowChange(Inventory $inventory) : void{
		$this->onCurrentWindowRemove();

		$this->openWindowDeferred(function() use ($inventory) : void{
			if(($slotMap = $this->createComplexSlotMapping($inventory)) !== null){
				$windowId = $this->addComplexDynamic($slotMap, $inventory);
			}else{
				$windowId = $this->addDynamic($inventory);
			}

			foreach($this->containerOpenCallbacks as $callback){
				$pks = $callback($windowId, $inventory);
				if($pks !== null){
					$windowType = null;
					foreach($pks as $pk){
						//Bedrock 1.21 requires ContainerClose packets to include the window type
						if($pk instanceof ContainerOpenPacket){
							$windowType = $pk->containerType;
						}elseif($pk instanceof ArrayPacket && $pk->getId() === PacketId::CONTAINER_OPEN){
							$type = $pk->fields["container_type"] ?? null;
							$windowType = is_int($type) ? $type : null;
						}
						$this->session->sendDataPacket($pk);
					}
					$this->currentWindowType = $windowType ?? WindowTypes::CONTAINER;
					$this->syncContents($inventory);
					return;
				}
			}
			throw new \LogicException("Unsupported inventory type");
		});
	}

	/** @phpstan-return ObjectSet<ContainerOpenClosure> */
	public function getContainerOpenCallbacks() : ObjectSet{ return $this->containerOpenCallbacks; }

	/**
	 * @return Packet[]|null
	 * @phpstan-return list<Packet>|null
	 */
	protected static function createContainerOpen(int $id, Inventory $inv) : ?array{
		//TODO: we should be using some kind of tagging system to identify the types. Instanceof is flaky especially
		//if the class isn't final, not to mention being inflexible.
		if($inv instanceof BlockInventory){
			$blockPosition = Fields::blockPosition($inv->getHolder());
			$windowType = match(true){
				$inv instanceof BlockInventoryWindow => $inv->getNetworkWindowType(),
				$inv instanceof LoomInventory => WindowTypes::LOOM,
				$inv instanceof FurnaceInventory => match($inv->getFurnaceType()){
						FurnaceType::FURNACE => WindowTypes::FURNACE,
						FurnaceType::BLAST_FURNACE => WindowTypes::BLAST_FURNACE,
						FurnaceType::SMOKER => WindowTypes::SMOKER,
						FurnaceType::CAMPFIRE, FurnaceType::SOUL_CAMPFIRE => throw new \LogicException("Campfire inventory cannot be displayed to a player")
					},
				$inv instanceof EnchantInventory => WindowTypes::ENCHANTMENT,
				$inv instanceof BrewingStandInventory => WindowTypes::BREWING_STAND,
				$inv instanceof AnvilInventory => WindowTypes::ANVIL,
				$inv instanceof HopperInventory => WindowTypes::HOPPER,
				$inv instanceof CraftingTableInventory => WindowTypes::WORKBENCH,
				$inv instanceof StonecutterInventory => WindowTypes::STONECUTTER,
				$inv instanceof CartographyTableInventory => WindowTypes::CARTOGRAPHY,
				$inv instanceof SmithingTableInventory => WindowTypes::SMITHING_TABLE,
				default => WindowTypes::CONTAINER
			};
			return [Packets::containerOpen(["container_id" => $id, "container_type" => $windowType, "position" => $blockPosition, "target_actor_id" => -1])];
		}
		return null;
	}

	public function onClientOpenMainInventory() : void{
		$this->onCurrentWindowRemove();

		$this->openWindowDeferred(function() : void{
			$windowId = $this->getNewWindowId();
			$this->associateIdWithInventory($windowId, $this->player->getInventory());
			$this->currentWindowType = WindowTypes::INVENTORY;

			$this->session->sendDataPacket(Packets::containerOpen([
				"container_id" => $windowId,
				"container_type" => $this->currentWindowType,
				"position" => ["x" => 0, "y" => 0, "z" => 0],
				"target_actor_id" => $this->player->getId(),
			]));
		});
	}

	public function onCurrentWindowRemove() : void{
		if(isset($this->networkIdToInventoryMap[$this->lastInventoryNetworkId])){
			$this->remove($this->lastInventoryNetworkId);
			$this->session->sendDataPacket(Packets::containerClose(["container_id" => $this->lastInventoryNetworkId, "container_type" => $this->currentWindowType, "server_initiated_close" => true]));
			if($this->pendingCloseWindowId !== null){
				throw new AssumptionFailedError("We should not have opened a new window while a window was waiting to be closed");
			}
			$this->pendingCloseWindowId = $this->lastInventoryNetworkId;
			$this->enchantingTableOptions = [];
		}
	}

	public function onClientRemoveWindow(int $id) : void{
		if(Binary::signByte($id) === ContainerIds::NONE){ //TODO: REMOVE signByte() once BedrockProtocol + ext-encoding are implemented
			//TODO: HACK! Since 1.21.100 (and probably earlier), the client will send -1 to close windows that it can't
			//view for some reason, e.g. if the chat window was already open. This is pretty awkward, since it means
			//that we can only assume it refers to the most recently sent window, and if we don't handle it,
			//InventoryManager will never get the green light to send subsequent windows, which breaks inventory UIs.
			//Fortunately, we already wait for close acks anyway, so the window ID is technically useless...?
			$this->session->getLogger()->debug("Client rejected opening of a window, assuming it was $this->lastInventoryNetworkId");
			$id = $this->lastInventoryNetworkId;
		}
		if($id === $this->lastInventoryNetworkId){
			if(isset($this->networkIdToInventoryMap[$id]) && $id !== $this->pendingCloseWindowId){
				$this->remove($id);
				$this->player->removeCurrentWindow();
			}
		}else{
			$this->session->getLogger()->debug("Attempted to close inventory with network ID $id, but current is $this->lastInventoryNetworkId");
		}

		//Always send this, even if no window matches. If we told the client to close a window, it will behave as if it
		//initiated the close and expect an ack.
		$this->session->sendDataPacket(Packets::containerClose(["container_id" => $id, "container_type" => $this->currentWindowType, "server_initiated_close" => false]));

		if($this->pendingCloseWindowId === $id){
			$this->pendingCloseWindowId = null;
			if($this->pendingOpenWindowCallback !== null){
				$this->session->getLogger()->debug("Opening deferred window after close ack of window $id");
				($this->pendingOpenWindowCallback)();
				$this->pendingOpenWindowCallback = null;
			}
		}
	}

	/**
	 * Compares itemstack extra data for equality. This is used to verify legacy InventoryTransaction slot predictions.
	 *
	 * TODO: It would be preferable if we didn't have to deserialize this, to improve performance and reduce attack
	 * surface. However, the raw data may not match due to differences in ordering. Investigate whether the
	 * client-provided NBT is consistently sorted.
	 */
	private function itemStackExtraDataEqual(ItemStack $left, ItemStack $right) : bool{
		if($left->getRawExtraData() === $right->getRawExtraData()){
			return true;
		}

		$typeConverter = $this->session->getTypeConverter();
		$leftExtraData = $typeConverter->deserializeItemStackExtraData($left->getRawExtraData(), $left->getId());
		$rightExtraData = $typeConverter->deserializeItemStackExtraData($right->getRawExtraData(), $right->getId());

		$leftNbt = $leftExtraData->getNbt();
		$rightNbt = $rightExtraData->getNbt();
		return
			$leftExtraData->getCanPlaceOn() === $rightExtraData->getCanPlaceOn() &&
			$leftExtraData->getCanDestroy() === $rightExtraData->getCanDestroy() && (
				$leftNbt === $rightNbt || //this covers null === null and fast object identity
				($leftNbt !== null && $rightNbt !== null && $leftNbt->equals($rightNbt))
			);
	}

	private function itemStacksEqual(ItemStack $left, ItemStack $right) : bool{
		return
			$left->getId() === $right->getId() &&
			$left->getMeta() === $right->getMeta() &&
			$left->getBlockRuntimeId() === $right->getBlockRuntimeId() &&
			$left->getCount() === $right->getCount() &&
			$this->itemStackExtraDataEqual($left, $right);
	}

	public function onSlotChange(Inventory $inventory, int $slot) : void{
		$inventoryEntry = $this->inventories[spl_object_id($inventory)] ?? null;
		if($inventoryEntry === null){
			//this can happen when an inventory changed during InventoryCloseEvent, or when a temporary inventory
			//is cleared before removal.
			return;
		}
		$currentItem = $this->session->getTypeConverter()->coreItemStackToNet($inventory->getItem($slot));
		$clientSideItem = $inventoryEntry->predictions[$slot] ?? null;
		if($clientSideItem === null || !$this->itemStacksEqual($currentItem, $clientSideItem)){
			//no prediction or incorrect - do not associate this with the currently active itemstack request
			$this->trackItemStack($inventoryEntry, $slot, $currentItem, null);
			$inventoryEntry->pendingSyncs[$slot] = $currentItem;
		}else{
			//correctly predicted - associate the change with the currently active itemstack request
			$this->trackItemStack($inventoryEntry, $slot, $currentItem, $this->currentItemStackRequestId);
		}

		unset($inventoryEntry->predictions[$slot]);
	}

	private function sendInventorySlotPackets(int $windowId, int $netSlot, ItemStackWrapper $itemStackWrapper) : void{
		/*
		 * TODO: HACK!
		 * As of 1.20.12, the client ignores change of itemstackID in some cases when the old item == the new item.
		 * Notably, this happens with armor, offhand and enchanting tables, but not with main inventory.
		 * While we could track the items previously sent to the client, that's a waste of memory and would
		 * cost performance. Instead, clear the slot(s) first, then send the new item(s).
		 * The network cost of doing this is fortunately minimal, as an air itemstack is only 1 byte.
		 */
		if($itemStackWrapper->getStackId() !== 0){
			$this->session->sendDataPacket(Packets::inventorySlot([
				"container_id" => $windowId,
				"slot" => $netSlot,
				"full_container_name" => null,
				"storage_item" => null,
				"item" => Fields::itemStack(ItemStack::null()),
			]));
		}
		//now send the real contents
		$this->session->sendDataPacket(Packets::inventorySlot([
			"container_id" => $windowId,
			"slot" => $netSlot,
			"full_container_name" => null,
			"storage_item" => null,
			"item" => Fields::itemStackWrapper($itemStackWrapper),
		]));
	}

	/**
	 * @param ItemStackWrapper[] $itemStackWrappers
	 */
	private function sendInventoryContentPackets(int $windowId, array $itemStackWrappers) : void{
		/*
		 * TODO: HACK!
		 * As of 1.20.12, the client ignores change of itemstackID in some cases when the old item == the new item.
		 * Notably, this happens with armor, offhand and enchanting tables, but not with main inventory.
		 * While we could track the items previously sent to the client, that's a waste of memory and would
		 * cost performance. Instead, clear the slot(s) first, then send the new item(s).
		 * The network cost of doing this is fortunately minimal, as an air itemstack is only 1 byte.
		 */
		$empty = Fields::itemStack(ItemStack::null());
		$this->session->sendDataPacket(Packets::inventoryContent([
			"container_id" => $windowId,
			"slots" => array_fill(0, count($itemStackWrappers), $empty),
			"full_container_name" => ["container_name" => 0, "dynamic_id" => null],
			"storage_item" => $empty,
		]));
		//now send the real contents
		$slots = [];
		foreach($itemStackWrappers as $itemStackWrapper){
			$slots[] = Fields::itemStackWrapper($itemStackWrapper);
		}
		$this->session->sendDataPacket(Packets::inventoryContent([
			"container_id" => $windowId,
			"slots" => $slots,
			"full_container_name" => ["container_name" => 0, "dynamic_id" => null],
			"storage_item" => $empty,
		]));
	}

	public function syncSlot(Inventory $inventory, int $slot, ItemStack $itemStack) : void{
		$entry = $this->inventories[spl_object_id($inventory)] ?? null;
		if($entry === null){
			throw new \LogicException("Cannot sync an untracked inventory");
		}
		$itemStackInfo = $entry->itemStackInfos[$slot];
		if($itemStackInfo === null){
			throw new \LogicException("Cannot sync an untracked inventory slot");
		}
		if($entry->complexSlotMap !== null){
			$windowId = ContainerIds::UI;
			$netSlot = $entry->complexSlotMap->mapCoreToNet($slot) ?? throw new AssumptionFailedError("We already have an ItemStackInfo, so this should not be null");
		}else{
			$windowId = $this->getWindowId($inventory) ?? throw new AssumptionFailedError("We already have an ItemStackInfo, so this should not be null");
			$netSlot = $slot;
		}

		$itemStackWrapper = new ItemStackWrapper($itemStackInfo->getStackId(), $itemStack);
		if($windowId === ContainerIds::OFFHAND){
			//TODO: HACK!
			//The client may sometimes ignore the InventorySlotPacket for the offhand slot.
			//This can cause a lot of problems (totems, arrows, and more...).
			//The workaround is to send an InventoryContentPacket instead
			//BDS (Bedrock Dedicated Server) also seems to work this way.
			$this->sendInventoryContentPackets($windowId, [$itemStackWrapper]);
		}else{
			$this->sendInventorySlotPackets($windowId, $netSlot, $itemStackWrapper);
		}
		unset($entry->predictions[$slot], $entry->pendingSyncs[$slot]);
	}

	public function syncContents(Inventory $inventory) : void{
		$entry = $this->inventories[spl_object_id($inventory)] ?? null;
		if($entry === null){
			//this can happen when an inventory changed during InventoryCloseEvent, or when a temporary inventory
			//is cleared before removal.
			return;
		}
		if($entry->complexSlotMap !== null){
			$windowId = ContainerIds::UI;
		}else{
			$windowId = $this->getWindowId($inventory);
		}
		if($windowId !== null){
			$entry->predictions = [];
			$entry->pendingSyncs = [];
			$contents = [];
			$typeConverter = $this->session->getTypeConverter();
			foreach($inventory->getContents(true) as $slot => $item){
				$itemStack = $typeConverter->coreItemStackToNet($item);
				$info = $this->trackItemStack($entry, $slot, $itemStack, null);
				$contents[] = new ItemStackWrapper($info->getStackId(), $itemStack);
			}
			if($entry->complexSlotMap !== null){
				foreach($contents as $slotId => $info){
					$packetSlot = $entry->complexSlotMap->mapCoreToNet($slotId) ?? null;
					if($packetSlot === null){
						continue;
					}
					$this->sendInventorySlotPackets($windowId, $packetSlot, $info);
				}
			}else{
				$this->sendInventoryContentPackets($windowId, $contents);
			}
		}
	}

	public function syncAll() : void{
		foreach($this->inventories as $entry){
			$this->syncContents($entry->inventory);
		}
	}

	public function requestSyncAll() : void{
		$this->fullSyncRequested = true;
	}

	public function syncMismatchedPredictedSlotChanges() : void{
		$typeConverter = $this->session->getTypeConverter();
		foreach($this->inventories as $entry){
			$inventory = $entry->inventory;
			foreach($entry->predictions as $slot => $expectedItem){
				if(!$inventory->slotExists($slot) || $entry->itemStackInfos[$slot] === null){
					continue; //TODO: size desync ???
				}

				//any prediction that still exists at this point is a slot that was predicted to change but didn't
				$this->session->getLogger()->debug("Detected prediction mismatch in inventory " . get_class($inventory) . "#" . spl_object_id($inventory) . " slot $slot");
				$entry->pendingSyncs[$slot] = $typeConverter->coreItemStackToNet($inventory->getItem($slot));
			}

			$entry->predictions = [];
		}
	}

	public function flushPendingUpdates() : void{
		if($this->fullSyncRequested){
			$this->fullSyncRequested = false;
			$this->session->getLogger()->debug("Full inventory sync requested, sending contents of " . count($this->inventories) . " inventories");
			$this->syncAll();
		}else{
			foreach($this->inventories as $entry){
				if(count($entry->pendingSyncs) === 0){
					continue;
				}
				$inventory = $entry->inventory;
				$this->session->getLogger()->debug("Syncing slots " . implode(", ", array_keys($entry->pendingSyncs)) . " in inventory " . get_class($inventory) . "#" . spl_object_id($inventory));
				foreach($entry->pendingSyncs as $slot => $itemStack){
					$this->syncSlot($inventory, $slot, $itemStack);
				}
				$entry->pendingSyncs = [];
			}
		}
	}

	public function syncData(Inventory $inventory, int $propertyId, int $value) : void{
		$windowId = $this->getWindowId($inventory);
		if($windowId !== null){
			$this->session->sendDataPacket(Packets::containerSetData(["container_id" => $windowId, "id" => $propertyId, "value" => $value]));
		}
	}

	public function onClientSelectHotbarSlot(int $slot) : void{
		$this->clientSelectedHotbarSlot = $slot;
	}

	public function syncSelectedHotbarSlot() : void{
		$playerInventory = $this->player->getInventory();
		$selected = $playerInventory->getHeldItemIndex();
		if($selected !== $this->clientSelectedHotbarSlot){
			$inventoryEntry = $this->inventories[spl_object_id($playerInventory)] ?? null;
			if($inventoryEntry === null){
				throw new AssumptionFailedError("Player inventory should always be tracked");
			}
			$itemStackInfo = $inventoryEntry->itemStackInfos[$selected] ?? null;
			if($itemStackInfo === null){
				throw new AssumptionFailedError("Untracked player inventory slot $selected");
			}

			$this->session->sendDataPacket(Packets::mobEquipment([
				"target_runtime_id" => $this->player->getId(),
				"item" => Fields::itemStack($this->session->getTypeConverter()->coreItemStackToNet($playerInventory->getItemInHand()), $itemStackInfo->getStackId()),
				"slot" => $selected,
				"selected_slot" => $selected,
				"container_id" => ContainerIds::INVENTORY,
			]));
			$this->clientSelectedHotbarSlot = $selected;
		}
	}

	public function syncCreative() : void{
		$this->session->sendDataPacket(CreativeInventoryCache::getInstance()->buildPacket($this->player->getCreativeInventory(), $this->session));
	}

	/**
	 * @param EnchantingOption[] $options
	 * @phpstan-param list<EnchantingOption> $options
	 */
	public function syncEnchantingTableOptions(array $options) : void{
		$protocolOptions = [];

		foreach($options as $index => $option){
			$optionId = $this->nextEnchantingTableOptionId++;
			$this->enchantingTableOptions[$optionId] = $index;

			$protocolEnchantments = [];
			foreach($option->getEnchantments() as $enchantment){
				$protocolEnchantments[] = ["enchant_type" => EnchantmentIdMap::getInstance()->toId($enchantment->getType()), "enchant_level" => $enchantment->getLevel()];
			}
			// We don't pay attention to the slot flags, held activated enchantments and self activated enchantments
			// as everything works fine without them (perhaps these values are used somehow in the BDS).
			$protocolOptions[] = [
				"cost" => $option->getRequiredXpLevel(),
				"enchants" => ["slot" => 0, "item_enchants" => [$protocolEnchantments, [], []]],
				"enchant_name" => $option->getDisplayName(),
				"enchant_net_id" => $optionId,
			];
		}

		$this->session->sendDataPacket(Packets::playerEnchantOptions(["options" => $protocolOptions]));
	}

	public function getEnchantingTableOptionIndex(int $recipeId) : ?int{
		return $this->enchantingTableOptions[$recipeId] ?? null;
	}

	private function newItemStackId() : int{
		return $this->nextItemStackId++;
	}

	public function getItemStackInfo(Inventory $inventory, int $slot) : ?ItemStackInfo{
		$entry = $this->inventories[spl_object_id($inventory)] ?? null;
		return $entry?->itemStackInfos[$slot] ?? null;
	}

	private function trackItemStack(InventoryManagerEntry $entry, int $slotId, ItemStack $itemStack, ?int $itemStackRequestId) : ItemStackInfo{
		//TODO: ItemStack->isNull() would be nice to have here
		$info = new ItemStackInfo($itemStackRequestId, $itemStack->getId() === 0 ? 0 : $this->newItemStackId());
		return $entry->itemStackInfos[$slotId] = $info;
	}
}
