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

namespace quark\player;

use DateTimeImmutable;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\LongTag;
use quark\entity\Human;
use quark\inventory\ArmorInventory;
use quark\inventory\Inventory;
use quark\inventory\SimpleInventory;

/**
 * A snapshot of a player's saved data. Inventory changes are only saved when made through
 * {@link \quark\Server::updateOfflinePlayer()}.
 */
class OfflinePlayer implements IPlayer{
	private readonly Inventory $inventory;
	private readonly ArmorInventory $armorInventory;
	private readonly Inventory $offHandInventory;
	private readonly Inventory $enderInventory;
	private bool $inventoriesLoaded = false;

	public function __construct(
		private string $name,
		private ?CompoundTag $namedtag
	){
		$this->inventory = new SimpleInventory(36);
		$this->armorInventory = new ArmorInventory();
		$this->offHandInventory = new SimpleInventory(1);
		$this->enderInventory = new SimpleInventory(27);
	}

	public function getName() : string{
		return $this->name;
	}

	public function getFirstPlayed() : ?DateTimeImmutable{
		return $this->readTime(Player::TAG_FIRST_PLAYED);
	}

	public function getLastPlayed() : ?DateTimeImmutable{
		return $this->readTime(Player::TAG_LAST_PLAYED);
	}

	private function readTime(string $tagName) : ?DateTimeImmutable{
		$tag = $this->namedtag?->getTag($tagName);
		return $tag instanceof LongTag ? DateTimeImmutable::createFromTimestamp($tag->getValue() / 1000) : null;
	}

	public function hasPlayedBefore() : bool{
		return $this->namedtag !== null;
	}

	public function getInventory() : Inventory{
		$this->loadInventories();
		return $this->inventory;
	}

	public function getArmorInventory() : ArmorInventory{
		$this->loadInventories();
		return $this->armorInventory;
	}

	public function getOffHandInventory() : Inventory{
		$this->loadInventories();
		return $this->offHandInventory;
	}

	public function getEnderInventory() : Inventory{
		$this->loadInventories();
		return $this->enderInventory;
	}

	private function loadInventories() : void{
		if(!$this->inventoriesLoaded){
			$this->inventoriesLoaded = true;
			if($this->namedtag !== null){
				Human::readInventories($this->namedtag, $this->inventory, $this->armorInventory, $this->offHandInventory, $this->enderInventory);
			}
		}
	}

	/**
	 * Returns the saved data with inventory changes applied, or null if the player has no saved data.
	 *
	 * @internal
	 */
	public function getSaveData() : ?CompoundTag{
		if($this->namedtag === null || !$this->inventoriesLoaded){
			return $this->namedtag;
		}
		$nbt = clone $this->namedtag;
		Human::writeInventories($nbt, $this->inventory, $this->armorInventory, $this->offHandInventory, $this->enderInventory);
		return $nbt;
	}
}
