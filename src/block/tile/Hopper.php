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

namespace quark\block\tile;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\IntTag;
use quark\block\Block;
use quark\block\BrewingStand as BlockBrewingStand;
use quark\block\Furnace as BlockFurnace;
use quark\block\Hopper as BlockHopper;
use quark\block\inventory\window\BrewingStandInventoryWindow as BrewingSlots;
use quark\block\inventory\window\FurnaceInventoryWindow as FurnaceSlots;
use quark\block\utils\Container;
use quark\block\utils\HopperConfig;
use quark\entity\object\ItemEntity;
use quark\event\block\HopperPickupItemEvent;
use quark\event\block\HopperTransferEvent;
use quark\inventory\CallbackInventoryListener;
use quark\inventory\CombinedInventoryProxy;
use quark\inventory\Inventory;
use quark\inventory\InventoryListener;
use quark\inventory\SimpleInventory;
use quark\inventory\SlotValidatedInventory;
use quark\item\Bucket;
use quark\item\GlassBottle;
use quark\item\Item;
use quark\item\ItemTypeIds;
use quark\item\Potion;
use quark\item\SplashPotion;
use quark\math\AxisAlignedBB;
use quark\math\Facing;
use quark\math\Vector3;
use quark\world\Position;
use quark\world\World;
use function abs;
use function count;
use function max;
use function min;
use function spl_object_id;

class Hopper extends Spawnable implements ContainerTile, Nameable{

	use ContainerTileTrait;
	use NameableTrait;

	private const TAG_TRANSFER_COOLDOWN = "TransferCooldown";

	/** Missed wake-ups are caught by a recheck that backs off from this delay... */
	private const MIN_RECHECK_DELAY = 40;
	/** ...to this one while nothing moves. */
	private const MAX_RECHECK_DELAY = 1200;

	private const FURNACE_INPUT = [FurnaceSlots::SLOT_INPUT];
	private const FURNACE_FUEL = [FurnaceSlots::SLOT_FUEL];
	private const BREWING_INGREDIENT = [BrewingSlots::SLOT_INGREDIENT];
	private const BREWING_FUEL = [BrewingSlots::SLOT_FUEL];
	private const BREWING_BOTTLES = [BrewingSlots::SLOT_BOTTLE_LEFT, BrewingSlots::SLOT_BOTTLE_MIDDLE, BrewingSlots::SLOT_BOTTLE_RIGHT];

	private Inventory $inventory;
	private InventoryListener $wakeListener;
	/**
	 * Neighbouring inventories that wake this hopper when they change, held weakly so a destroyed container can go.
	 * @var \WeakReference[]
	 * @phpstan-var array<int, \WeakReference<Inventory>>
	 */
	private array $watched = [];

	/** Remaining cooldown while the tile isn't in a world. */
	private int $savedCooldown = 0;
	/** Server tick before which the hopper may not move items. */
	private int $cooldownUntil = 0;
	/** Tick of the queued block update, or -1. */
	private int $scheduledFor = -1;
	/** Tick the hopper first became due while the update budget held it back, or -1. */
	private int $dueSince = -1;
	/** Wake-ups wait until this tick after an update that moved nothing. */
	private int $idleUntil = 0;
	private int $recheckDelay = self::MIN_RECHECK_DELAY;
	/** Whether dropped items may be waiting in the pickup area. */
	private bool $pickupPending = true;
	/** A hopper with a container above pulls from it and collects nothing, so dropped items don't wake it. */
	private bool $pullsFromAbove = false;
	/** Mirrors the block's powered state, since dropped items report through block copies that may be stale. */
	private bool $locked = false;
	private bool $updating = false;

	public function __construct(){
		parent::__construct();
		$this->inventory = new SimpleInventory(5);
		//a weak reference keeps the listener from tying the tile into a reference cycle
		$tile = \WeakReference::create($this);
		$this->wakeListener = CallbackInventoryListener::onAnyChange(static function(Inventory $unused) use ($tile) : void{
			$tile->get()?->wake();
		});
		$this->inventory->getListeners()->add($this->wakeListener);
	}

	public function readSaveData(CompoundTag $nbt) : void{
		$this->loadItems($nbt);
		$this->loadName($nbt);

		$cooldown = $nbt->getTag(self::TAG_TRANSFER_COOLDOWN);
		$this->savedCooldown = $cooldown instanceof IntTag ? max(0, min($cooldown->getValue(), HopperConfig::getTransferCooldown())) : 0;
		if($this->isAttached()){
			$this->cooldownUntil = $this->getPosition()->getWorld()->getServer()->getTick() + $this->savedCooldown;
		}
	}

	protected function writeSaveData(CompoundTag $nbt) : void{
		$this->saveItems($nbt);
		$this->saveName($nbt);

		//saved as remaining ticks, since server ticks start over after a restart
		$nbt->setInt(self::TAG_TRANSFER_COOLDOWN, $this->getTransferCooldown());
	}

	public function attach(Position $position) : void{
		parent::attach($position);
		$this->cooldownUntil = $position->getWorld()->getServer()->getTick() + $this->savedCooldown;
		$this->scheduledFor = -1;
		$this->dueSince = -1;
		$this->pickupPending = true;
	}

	public function detach() : void{
		$this->savedCooldown = $this->getTransferCooldown();
		$this->unwatchAll();
		parent::detach();
	}

	public function close() : void{
		if(!$this->closed){
			$this->inventory->removeAllWindows();
			$this->unwatchAll();

			parent::close();
		}
	}

	public function getDefaultName() : string{
		return "Hopper";
	}

	public function getInventory() : Inventory{
		return $this->inventory;
	}

	/**
	 * Returns the number of ticks before the hopper may move items again.
	 */
	public function getTransferCooldown() : int{
		if(!$this->isAttached()){
			return $this->savedCooldown;
		}
		$position = $this->getPosition();
		return $position->isValid() ? max(0, $this->cooldownUntil - $position->getWorld()->getServer()->getTick()) : 0;
	}

	/**
	 * Queues an update for when the cooldown ends, so the hopper reacts to something that may give it work. After an
	 * update that moved nothing, it waits as long as a cooldown too, so busy neighbours can't make it retry every tick.
	 */
	public function wake() : void{
		if($this->updating || $this->locked || $this->closed || !$this->isAttached() || !HopperConfig::isEnabled()){
			return;
		}
		$position = $this->getPosition();
		$world = $position->getWorld();
		$now = $world->getServer()->getTick();
		$at = max($now + 1, $this->cooldownUntil, $this->idleUntil);
		if($this->scheduledFor < $now || $this->scheduledFor > $at){
			$this->schedule($world, $position, $now, $at - $now);
		}
	}

	/**
	 * @internal Called by the hopper block with its current powered state. Unlocking wakes the hopper.
	 */
	public function setLocked(bool $locked) : void{
		$wasLocked = $this->locked;
		$this->locked = $locked;
		if($wasLocked && !$locked){
			$this->wake();
		}
	}

	/**
	 * @internal Called by the hopper block when a dropped item is in or above it.
	 */
	public function onItemNearby(ItemEntity $entity) : void{
		if($entity->getPickupDelay() === ItemEntity::NEVER_DESPAWN || $entity->isFlaggedForDespawn()){
			return;
		}
		$this->pickupPending = true;
		if($this->locked || $this->pullsFromAbove || $this->closed || !$this->isAttached()){
			return;
		}
		//dropped items report every tick, so the cheap checks go first
		$now = $this->getPosition()->getWorld()->getServer()->getTick();
		if($this->scheduledFor >= $now && $this->scheduledFor <= max($now + 1, $this->cooldownUntil, $this->idleUntil)){
			return;
		}
		if(self::room($this->inventory, null, $entity->getItem(), 1, Inventory::MAX_STACK) > 0){
			$this->wake();
		}
	}

	private function schedule(World $world, Position $position, int $now, int $delay, int $waited = 0) : void{
		$world->scheduleDelayedBlockUpdate($position, $delay, $waited);
		$this->scheduledFor = $now + $delay;
	}

	/**
	 * @internal Called from the hopper block's scheduled update.
	 */
	public function onBlockUpdate(BlockHopper $block) : void{
		if($this->closed || !$this->isAttached() || $this->updating){
			return;
		}
		$this->scheduledFor = -1;
		$this->locked = $block->isPowered();
		//a locked or disabled hopper sleeps until power or the server wakes it, keeping its cooldown
		if(!HopperConfig::isEnabled() || $this->locked){
			$this->dueSince = -1;
			return;
		}
		$position = $this->getPosition();
		$world = $position->getWorld();
		$now = $world->getServer()->getTick();
		if($now < $this->cooldownUntil){
			$this->schedule($world, $position, $now, $this->cooldownUntil - $now);
			return;
		}
		if(!HopperConfig::tryClaimUpdate($now)){
			if($this->dueSince < 0){
				$this->dueSince = $now;
			}
			$this->schedule($world, $position, $now, 1, $now + 1 - $this->dueSince);
			return;
		}
		$this->dueSince = -1;

		$scope = $this->pulse->start();
		$this->updating = true;
		try{
			$moved = $this->transferItems($block, $position, $now);
		}catch(\Throwable $e){
			//a plugin listener failed, so keep a recheck queued rather than leave the hopper asleep
			$this->schedule($world, $position, $now, $this->recheckDelay);
			throw $e;
		}finally{
			$this->updating = false;
			$this->pulse->stop($scope);
		}

		//listeners may have broken the hopper meanwhile
		if($this->isClosed() || !$this->isAttached()){
			return;
		}
		if($moved){
			$this->cooldownUntil = $now + HopperConfig::getTransferCooldown();
			$this->recheckDelay = self::MIN_RECHECK_DELAY;
			$this->schedule($world, $position, $now, HopperConfig::getTransferCooldown());
		}else{
			$this->idleUntil = $now + HopperConfig::getTransferCooldown();
			//hoppers loaded together would otherwise recheck on the same ticks forever
			$spread = abs($position->getFloorX() * 7 + $position->getFloorZ() * 13 + $position->getFloorY()) % (($this->recheckDelay >> 2) + 1);
			$this->schedule($world, $position, $now, $this->recheckDelay + $spread);
			$this->recheckDelay = min($this->recheckDelay * 2, self::MAX_RECHECK_DELAY);
		}
	}

	private function transferItems(BlockHopper $block, Position $position, int $now) : bool{
		$this->unwatchAll();
		$world = $position->getWorld();
		$x = $position->getFloorX();
		$y = $position->getFloorY();
		$z = $position->getFloorZ();
		$limit = HopperConfig::getItemsPerTransfer();
		$moved = false;

		if(!$this->isEmpty()){
			$facing = $block->getFacing();
			[$dx, $dy, $dz] = Facing::OFFSET[$facing];
			$target = self::containerAt($world, $x + $dx, $y + $dy, $z + $dz, $targetInventory, $targetOwners);
			if($target !== null){
				$moved = $this->push($block, $position, $target, $targetInventory, $targetOwners, Facing::opposite($facing), $limit, $now);
				if(!$moved && !$this->isClosed()){
					$this->watch($targetInventory);
				}
			}
		}

		if($this->isClosed()){
			return $moved;
		}
		$source = self::containerAt($world, $x, $y + 1, $z, $sourceInventory, $sourceOwners);
		$this->pullsFromAbove = $source !== null;
		if($source !== null){
			if($this->pull($block, $position, $source, $sourceInventory, $sourceOwners, $limit)){
				$moved = true;
			}elseif(!$this->isClosed()){
				$this->watch($sourceInventory);
			}
		}elseif($this->pickupPending && $this->pickup($block, $world, $x, $y, $z)){
			$moved = true;
		}

		return $moved;
	}

	/**
	 * Returns the container block with its inventory and the tiles that own it, so a transfer can tell when a listener
	 * destroys the container while items are moving.
	 *
	 * @param Tile[]|null $owners
	 * @phpstan-param-out Inventory $inventory
	 * @phpstan-param-out list<Tile> $owners
	 */
	private static function containerAt(World $world, int $x, int $y, int $z, ?Inventory &$inventory, ?array &$owners) : (Block&Container)|null{
		if(!$world->isChunkLoaded($x >> 4, $z >> 4)){
			return null;
		}
		//the tile lookup is far cheaper than the block one, and most hoppers have plain air above them
		$tile = $world->getTileAt($x, $y, $z);
		if(!$tile instanceof ContainerTile){
			return null;
		}
		$block = $world->getBlockAt($x, $y, $z);
		if(!$block instanceof Container){
			return null;
		}
		//only a double chest needs the block to lay out its combined view
		$pair = $tile instanceof Chest && $tile->isPaired() ? $tile->getPair() : null;
		$found = $pair !== null ? $block->getInventory() : $tile->getInventory();
		if($found === null){
			return null;
		}
		$inventory = $found;
		$owners = $pair !== null ? [$tile, $pair] : [$tile];
		return $block;
	}

	/**
	 * @param Tile[] $owners
	 * @phpstan-param list<Tile> $owners
	 */
	private static function alive(array $owners) : bool{
		foreach($owners as $owner){
			if($owner->isClosed() || !$owner->isAttached()){
				return false;
			}
		}
		return true;
	}

	/**
	 * @param Tile[] $targetOwners
	 * @phpstan-param list<Tile> $targetOwners
	 */
	private function push(BlockHopper $block, Position $position, Block $target, Inventory $targetInventory, array $targetOwners, int $side, int $limit, int $now) : bool{
		//like vanilla, an item entering an empty hopper waits out a full cooldown there, so it can't cross a chain in one tick
		$targetHopper = $targetOwners[0] instanceof self && $targetOwners[0]->isEmpty() ? $targetOwners[0] : null;
		$previousCooldown = 0;
		if($targetHopper !== null){
			$previousCooldown = $targetHopper->cooldownUntil;
			$targetHopper->cooldownUntil = max($previousCooldown, $now + HopperConfig::getTransferCooldown());
		}

		$moved = false;
		$ownOwners = [$this];
		for($slot = 0, $size = $this->inventory->getSize(); $slot < $size && !$moved; ++$slot){
			if($this->inventory->isSlotEmpty($slot)){
				continue;
			}
			$item = $this->inventory->getItem($slot);
			$slots = self::insertSlots($target, $item, $side, $slotCap);
			if($slots !== []){
				$moved = $this->moveSlot($block, $position, $this->inventory, $ownOwners, $slot, $item, $targetInventory, $targetOwners, $slots, $slotCap, $limit) > 0;
			}
		}

		if(!$moved && $targetHopper !== null && $targetHopper->isEmpty()){
			$targetHopper->cooldownUntil = $previousCooldown;
		}
		return $moved;
	}

	/**
	 * @param Tile[] $sourceOwners
	 * @phpstan-param list<Tile> $sourceOwners
	 */
	private function pull(BlockHopper $block, Position $position, Block $source, Inventory $sourceInventory, array $sourceOwners, int $limit) : bool{
		$slots = self::extractSlots($source, $sourceInventory);
		$count = $slots === null ? $sourceInventory->getSize() : count($slots);
		$ownOwners = [$this];
		for($i = 0; $i < $count; ++$i){
			$slot = $slots === null ? $i : $slots[$i];
			if($sourceInventory->isSlotEmpty($slot)){
				continue;
			}
			if($this->moveSlot($block, $position, $sourceInventory, $sourceOwners, $slot, $sourceInventory->getItem($slot), $this->inventory, $ownOwners, null, Inventory::MAX_STACK, $limit) > 0){
				return true;
			}
		}
		return false;
	}

	private function pickup(BlockHopper $block, World $world, int $x, int $y, int $z) : bool{
		$this->pickupPending = false;
		//the bowl and the whole block space above it
		$area = new AxisAlignedBB($x, $y + 11 / 16, $z, $x + 1, $y + 2, $z + 1);
		foreach($world->getNearbyEntities($area) as $entity){
			if(!$entity instanceof ItemEntity || $entity->isFlaggedForDespawn() || $entity->isClosed() || $entity->getPickupDelay() === ItemEntity::NEVER_DESPAWN){
				continue;
			}
			$item = clone $entity->getItem();
			//like vanilla, a hopper takes as much of a dropped stack as fits rather than a few items at a time
			$want = self::room($this->inventory, null, $item, $item->getCount(), Inventory::MAX_STACK);
			if($want > 0 && HopperPickupItemEvent::hasHandlers()){
				$ev = new HopperPickupItemEvent($block, $this->inventory, $entity, (clone $item)->setCount($want));
				$ev->call();
				if($ev->isCancelled()){
					$want = 0;
				}
			}
			$taken = $want > 0 ? $this->takeFromEntity($entity, $item, $want) : 0;
			if($taken < $item->getCount()){
				$this->pickupPending = true;
			}
			if($taken > 0 || $this->isClosed()){
				return $taken > 0;
			}
		}
		return false;
	}

	private function takeFromEntity(ItemEntity $entity, Item $expected, int $want) : int{
		//the event may have run plugin code, so check both ends again right before taking anything
		$current = $entity->getItem();
		if($this->isClosed() || $entity->isFlaggedForDespawn() || $entity->isClosed() || !$current->canStackWith($expected)){
			return 0;
		}
		$count = $current->getCount();
		$take = min($want, $count);
		$moving = (clone $current)->setCount($take);
		$remaining = $take;
		try{
			if($take >= $count){
				$entity->flagForDespawn();
			}else{
				$entity->setStackSize($count - $take);
			}
			self::place($this->inventory, [$this], null, $moving, Inventory::MAX_STACK, $remaining, self::hasValidators($this->inventory));
		}finally{
			$taken = $take - $remaining;
			if($remaining > 0){
				self::returnToEntity($entity, $moving, $remaining);
			}
		}
		return $taken;
	}

	private static function returnToEntity(ItemEntity $entity, Item $moving, int $remaining) : void{
		if(!$entity->isFlaggedForDespawn() && !$entity->isClosed()){
			$current = $entity->getItem();
			$count = $current->getCount();
			if($current->canStackWith($moving) && $count < $current->getMaxStackSize()){
				$back = min($remaining, $current->getMaxStackSize() - $count);
				$entity->setStackSize($count + $back);
				$remaining -= $back;
			}
		}
		if($remaining > 0){
			$entity->getWorld()->dropItem($entity->getLocation(), (clone $moving)->setCount($remaining), Vector3::zero());
		}
	}

	/**
	 * Moves up to $limit items out of one slot, checking where they fit before touching the source.
	 *
	 * @param Tile[]     $fromOwners
	 * @param Tile[]     $toOwners
	 * @param int[]|null $targetSlots
	 * @phpstan-param list<Tile>     $fromOwners
	 * @phpstan-param list<Tile>     $toOwners
	 * @phpstan-param list<int>|null $targetSlots
	 */
	private function moveSlot(BlockHopper $block, Position $position, Inventory $from, array $fromOwners, int $fromSlot, Item $item, Inventory $to, array $toOwners, ?array $targetSlots, int $slotCap, int $limit) : int{
		$validated = self::hasValidators($to);
		$want = self::room($to, $targetSlots, $item, min($limit, $item->getCount()), $slotCap, $validated);
		if($want <= 0){
			return 0;
		}
		$evented = HopperTransferEvent::hasHandlers();
		if($evented){
			$ev = new HopperTransferEvent($block, $from, $fromSlot, $to, (clone $item)->setCount($want));
			$ev->call();
			if($ev->isCancelled()){
				return 0;
			}
		}

		//slot validators and the event are plugin code, so after them both ends are checked again; from here until the
		//items are placed, every slot is read and written back with nothing running in between
		if($validated || $evented){
			if(!self::alive($fromOwners) || !self::alive($toOwners)){
				return 0;
			}
			$current = $from->getItem($fromSlot);
			if(!$current->canStackWith($item)){
				return 0;
			}
		}else{
			$current = $item;
		}
		$take = min($want, $current->getCount());
		if($take <= 0){
			return 0;
		}
		$moving = (clone $current)->setCount($take);
		$remaining = $take;
		try{
			$from->setItem($fromSlot, $current->setCount($current->getCount() - $take));
			//listeners may have filled the slots or destroyed the target meanwhile, leaving some in hand
			self::place($to, $toOwners, $targetSlots, $moving, $slotCap, $remaining, $validated);
		}finally{
			$moved = $take - $remaining;
			if($remaining > 0){
				self::place($from, $fromOwners, [$fromSlot], $moving, Inventory::MAX_STACK, $remaining, false);
				self::place($from, $fromOwners, null, $moving, Inventory::MAX_STACK, $remaining, false);
				if($remaining > 0){
					//the source filled up or went away while the items were in hand, so they go into the world instead of vanishing
					$position->getWorld()->dropItem($position->add(0.5, 0.5, 0.5), (clone $moving)->setCount($remaining), Vector3::zero());
				}
			}
		}
		return $moved;
	}

	/**
	 * Returns how many of the item, up to $want, fit into the given slots.
	 *
	 * @param int[]|null $slots
	 * @phpstan-param list<int>|null $slots
	 */
	private static function room(Inventory $inventory, ?array $slots, Item $item, int $want, int $slotCap, ?bool $validate = null) : int{
		$max = min($inventory->getMaxStackSize(), $item->getMaxStackSize(), $slotCap);
		$count = $slots === null ? $inventory->getSize() : count($slots);
		$validate ??= self::hasValidators($inventory);
		$room = 0;
		for($i = 0; $i < $count; ++$i){
			$slot = $slots === null ? $i : $slots[$i];
			if($validate && !self::accepts($inventory, $slot, $item)){
				continue;
			}
			if($inventory->isSlotEmpty($slot)){
				$room += $max;
			}else{
				$slotCount = $inventory->getMatchingItemCount($slot, $item, true);
				if($slotCount > 0 && $slotCount < $max){
					$room += $max - $slotCount;
				}
			}
			if($room >= $want){
				return $want;
			}
		}
		return $room;
	}

	/**
	 * Puts $remaining of the item into the given slots, topping up matching stacks before using empty slots like other
	 * containers do. Each slot is read right before it's written, with nothing running in between.
	 *
	 * Stops early if the inventory's owners are destroyed, since anything written after that would be lost.
	 *
	 * @param Tile[]     $owners
	 * @param int[]|null $slots
	 * @phpstan-param list<Tile>     $owners
	 * @phpstan-param list<int>|null $slots
	 */
	private static function place(Inventory $inventory, array $owners, ?array $slots, Item $item, int $slotCap, int &$remaining, bool $validate) : void{
		$max = min($inventory->getMaxStackSize(), $item->getMaxStackSize(), $slotCap);
		$count = $slots === null ? $inventory->getSize() : count($slots);
		for($pass = 0; $pass < 2 && $remaining > 0; ++$pass){
			for($i = 0; $i < $count && $remaining > 0; ++$i){
				$slot = $slots === null ? $i : $slots[$i];
				//validators are plugin code, so they run before the slot is read
				if($validate && !self::accepts($inventory, $slot, $item)){
					continue;
				}
				if($pass === 0){
					$slotCount = $inventory->getMatchingItemCount($slot, $item, true);
					if($slotCount === 0 || $slotCount >= $max){
						continue;
					}
					$put = min($max - $slotCount, $remaining);
					$current = $inventory->getItem($slot)->setCount($slotCount + $put);
				}elseif($inventory->isSlotEmpty($slot)){
					$put = min($max, $remaining);
					$current = (clone $item)->setCount($put);
				}else{
					continue;
				}
				if(!self::alive($owners)){
					return;
				}
				//counted before writing, since a listener throwing after the write must not make these look unplaced
				$remaining -= $put;
				$inventory->setItem($slot, $current);
			}
		}
	}

	private static function hasValidators(Inventory $inventory) : bool{
		if($inventory instanceof CombinedInventoryProxy){
			foreach($inventory->getBackingInventories() as $backing){
				if(self::hasValidators($backing)){
					return true;
				}
			}
			return false;
		}
		return $inventory instanceof SlotValidatedInventory && $inventory->getSlotValidators()->toArray() !== [];
	}

	private static function accepts(Inventory $inventory, int $slot, Item $item) : bool{
		if($inventory instanceof CombinedInventoryProxy){
			$inventory = $inventory->getBackingInventory($slot, $slot);
		}
		if($inventory instanceof SlotValidatedInventory){
			foreach($inventory->getSlotValidators()->toArray() as $validator){
				if($validator->validate($inventory, $item, $slot) !== null){
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Returns the slots a hopper may fill with the item when it enters the block from the given side, or null for any.
	 *
	 * @return int[]|null
	 * @phpstan-return list<int>|null
	 * @phpstan-param-out int $slotCap
	 */
	private static function insertSlots(Block $target, Item $item, int $side, ?int &$slotCap) : ?array{
		$slotCap = Inventory::MAX_STACK;
		if($target instanceof BlockFurnace){
			if($side === Facing::UP){
				return self::FURNACE_INPUT;
			}
			return $item->getFuelTime() > 0 ? self::FURNACE_FUEL : [];
		}
		if($target instanceof BlockBrewingStand){
			if($side === Facing::UP){
				return $target->getPosition()->getWorld()->getServer()->getCraftingManager()->isBrewingIngredient($item) ? self::BREWING_INGREDIENT : [];
			}
			if($item->getTypeId() === ItemTypeIds::BLAZE_POWDER){
				return self::BREWING_FUEL;
			}
			if($item instanceof Potion || $item instanceof SplashPotion || $item instanceof GlassBottle){
				$slotCap = 1;
				return self::BREWING_BOTTLES;
			}
			return [];
		}
		return null;
	}

	/**
	 * Returns the slots a hopper below the block may take from, or null for any.
	 *
	 * @return int[]|null
	 * @phpstan-return list<int>|null
	 */
	private static function extractSlots(Block $source, Inventory $inventory) : ?array{
		if($source instanceof BlockFurnace){
			//fuel residue such as the bucket left by lava comes out too
			return $inventory->getItem(FurnaceSlots::SLOT_FUEL) instanceof Bucket ? [FurnaceSlots::SLOT_RESULT, FurnaceSlots::SLOT_FUEL] : [FurnaceSlots::SLOT_RESULT];
		}
		if($source instanceof BlockBrewingStand){
			return self::BREWING_BOTTLES;
		}
		return null;
	}

	private function isEmpty() : bool{
		for($slot = 0, $size = $this->inventory->getSize(); $slot < $size; ++$slot){
			if(!$this->inventory->isSlotEmpty($slot)){
				return false;
			}
		}
		return true;
	}

	private function watch(Inventory $inventory) : void{
		foreach($inventory instanceof CombinedInventoryProxy ? $inventory->getBackingInventories() : [$inventory] as $backing){
			$id = spl_object_id($backing);
			if(($this->watched[$id] ?? null)?->get() === $backing){
				continue;
			}
			$backing->getListeners()->add($this->wakeListener);
			$this->watched[$id] = \WeakReference::create($backing);
		}
	}

	private function unwatchAll() : void{
		foreach($this->watched as $reference){
			$reference->get()?->getListeners()->remove($this->wakeListener);
		}
		$this->watched = [];
	}
}
