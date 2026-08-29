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

use PHPUnit\Framework\TestCase;
use quark\block\RuntimeBlockStateRegistry;
use quark\item\VanillaItems;
use quark\world\format\io\GlobalBlockStateHandlers;

final class ItemSerializerDeserializerTest extends TestCase{

	private ItemDeserializer $deserializer;
	private ItemSerializer $serializer;

	public function setUp() : void{
		$this->deserializer = new ItemDeserializer(GlobalBlockStateHandlers::getDeserializer());
		$this->serializer = new ItemSerializer(GlobalBlockStateHandlers::getSerializer());
	}

	public function testAllVanillaItemsSerializableAndDeserializable() : void{
		foreach(VanillaItems::getAll() as $item){
			if($item->isNull()){
				continue;
			}

			try{
				$itemData = $this->serializer->serializeType($item);
			}catch(ItemTypeSerializeException $e){
				self::fail($e->getMessage());
			}
			try{
				$newItem = $this->deserializer->deserializeType($itemData);
			}catch(ItemTypeDeserializeException $e){
				self::fail($e->getMessage());
			}

			self::assertTrue($item->equalsExact($newItem));
		}
	}

	public function testAllVanillaBlocksSerializableAndDeserializable() : void{
		foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $block){
			$item = $block->asItem();
			if($item->isNull()){
				continue;
			}

			try{
				$itemData = $this->serializer->serializeType($item);
			}catch(ItemTypeSerializeException $e){
				self::fail($e->getMessage());
			}
			try{
				$newItem = $this->deserializer->deserializeType($itemData);
			}catch(ItemTypeDeserializeException $e){
				self::fail($e->getMessage());
			}

			self::assertTrue($item->equalsExact($newItem));
		}
	}
}
