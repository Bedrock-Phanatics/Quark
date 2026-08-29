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

namespace quark\data\bedrock\item;

use quark\block\Block;
use quark\block\RuntimeBlockStateRegistry;
use quark\data\bedrock\block\BlockStateDeserializeException;
use quark\data\bedrock\block\BlockStateDeserializer;
use quark\data\bedrock\block\convert\UnsupportedBlockStateException;
use quark\data\bedrock\item\SavedItemData as Data;
use quark\item\Durable;
use quark\item\Item;
use pocketmine\nbt\NbtException;
use function min;

final class ItemDeserializer{
	/**
	 * @var \Closure[]
	 * @phpstan-var array<string, \Closure(Data) : Item>
	 */
	private array $deserializers = [];

	public function __construct(
		private BlockStateDeserializer $blockStateDeserializer
	){
		new ItemSerializerDeserializerRegistrar($this, null);
	}

	/**
	 * @phpstan-param \Closure(Data) : Item $deserializer
	 */
	public function map(string $id, \Closure $deserializer) : void{
		$this->deserializers[$id] = $deserializer;
	}

	/**
	 * Returns the existing data deserializer for the given ID, or null if none exists.
	 * This may be useful if you need to override a deserializer, but still want to be able to fall back to the original.
	 *
	 * @phpstan-return ?\Closure(Data) : Item
	 */
	public function getDeserializerForId(string $id) : ?\Closure{
		return $this->deserializers[$id] ?? null;
	}

	/**
	 * @phpstan-param \Closure(Data) : Block $deserializer
	 */
	public function mapBlock(string $id, \Closure $deserializer) : void{
		$this->map($id, fn(Data $data) => $deserializer($data)->asItem());
	}

	/**
	 * @throws ItemTypeDeserializeException
	 */
	public function deserializeType(Data $data) : Item{
		if(($blockData = $data->getBlock()) !== null){
			//TODO: this is rough duct tape; we need a better way to deal with this
			try{
				$block = $this->blockStateDeserializer->deserialize($blockData);
			}catch(UnsupportedBlockStateException $e){
				throw new UnsupportedItemTypeException($e->getMessage(), 0, $e);
			}catch(BlockStateDeserializeException $e){
				throw new ItemTypeDeserializeException("Failed to deserialize item data: " . $e->getMessage(), 0, $e);
			}

			//TODO: worth caching this or not?
			return RuntimeBlockStateRegistry::getInstance()->fromStateId($block)->asItem();
		}
		$id = $data->getName();
		if(!isset($this->deserializers[$id])){
			throw new UnsupportedItemTypeException("No deserializer found for ID $id");
		}

		return ($this->deserializers[$id])($data);
	}

	/**
	 * @throws ItemTypeDeserializeException
	 */
	public function deserializeStack(SavedItemStackData $data) : Item{
		$itemStack = $this->deserializeType($data->getTypeData());

		$itemStack->setCount($data->getCount());
		if(($tagTag = $data->getTypeData()->getTag()) !== null){
			try{
				$itemStack->setNamedTag(clone $tagTag);
			}catch(NbtException $e){
				throw new ItemTypeDeserializeException("Invalid item saved NBT: " . $e->getMessage(), 0, $e);
			}
		}

		//TODO: this hack is necessary to get legacy tools working - we need a better way to handle this kind of stuff
		if($itemStack instanceof Durable && $itemStack->getDamage() === 0 && ($damage = $data->getTypeData()->getMeta()) > 0){
			$itemStack->setDamage(min($damage, $itemStack->getMaxDurability()));
		}

		//TODO: canDestroy, canPlaceOn, wasPickedUp are currently unused

		return $itemStack;
	}
}
