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

namespace quark\network\mcpe\protocol;

use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataTypes;
use function array_key_first;
use function array_values;

/**
 * An entity's network properties, kept as the entries actor_data fields take, with the ones that changed since they
 * were last sent.
 *
 * @phpstan-import-type DataItemEntry from Packets
 * @phpstan-import-type DataItemEntryPayload from Packets
 */
final class EntityMetadata{
	/** @phpstan-var array<int, DataItemEntry> */
	private array $properties = [];
	/** @phpstan-var array<int, DataItemEntry> */
	private array $dirtyProperties = [];

	private static function checkRange(int $value, int $min, int $max) : void{
		if($value < $min || $value > $max){
			throw new \InvalidArgumentException("Value $value is out of range $min - $max");
		}
	}

	public function setByte(int $key, int $value, bool $force = false) : void{
		self::checkRange($value, -0x80, 0x7f);
		$this->set($key, ["byte" => ["type" => EntityMetadataTypes::BYTE, "value" => $value]], $force);
	}

	public function setShort(int $key, int $value, bool $force = false) : void{
		self::checkRange($value, -0x8000, 0x7fff);
		$this->set($key, ["short" => ["type" => EntityMetadataTypes::SHORT, "value" => $value]], $force);
	}

	public function setInt(int $key, int $value, bool $force = false) : void{
		self::checkRange($value, -0x80000000, 0x7fffffff);
		$this->set($key, ["int" => ["type" => EntityMetadataTypes::INT, "value" => $value]], $force);
	}

	public function setFloat(int $key, float $value, bool $force = false) : void{
		$this->set($key, ["float" => ["type" => EntityMetadataTypes::FLOAT, "value" => $value]], $force);
	}

	public function setString(int $key, string $value, bool $force = false) : void{
		$this->set($key, ["string" => ["type" => EntityMetadataTypes::STRING, "value" => $value]], $force);
	}

	/**
	 * @param string $nbt a compound tag in the network NBT encoding
	 */
	public function setCompoundTag(int $key, string $nbt, bool $force = false) : void{
		$this->set($key, ["compound_tag" => ["type" => EntityMetadataTypes::COMPOUND_TAG, "value" => $nbt]], $force);
	}

	public function setBlockPos(int $key, ?Vector3 $value, bool $force = false) : void{
		$this->set($key, ["pos" => ["type" => EntityMetadataTypes::POS, "value" => $value !== null ? Fields::blockPosition($value) : ["x" => 0, "y" => 0, "z" => 0]]], $force);
	}

	public function setLong(int $key, int $value, bool $force = false) : void{
		$this->set($key, ["int64" => ["type" => EntityMetadataTypes::LONG, "value" => $value]], $force);
	}

	public function setVector3(int $key, ?Vector3 $value, bool $force = false) : void{
		$this->set($key, ["vec3" => ["type" => EntityMetadataTypes::VECTOR3F, "value" => $value !== null ? Fields::vec3($value) : ["x" => 0.0, "y" => 0.0, "z" => 0.0]]], $force);
	}

	/**
	 * @phpstan-param DataItemEntryPayload $payload
	 */
	private function set(int $key, array $payload, bool $force) : void{
		$current = $this->properties[$key] ?? null;
		if($current !== null){
			if(!$force && array_key_first($current["payload"]) !== array_key_first($payload)){
				throw new \InvalidArgumentException("Can't overwrite property $key of type " . array_key_first($current["payload"]) . " with " . array_key_first($payload));
			}
			if($current["payload"] === $payload){
				return;
			}
		}
		$this->properties[$key] = $this->dirtyProperties[$key] = ["id" => $key, "payload" => $payload];
	}

	public function setGenericFlag(int $flagId, bool $value) : void{
		$propertyId = $flagId >= 64 ? EntityMetadataProperties::FLAGS2 : EntityMetadataProperties::FLAGS;
		$realFlagId = $flagId % 64;
		$payload = ($this->properties[$propertyId] ?? null)["payload"] ?? null;
		if($payload === null){
			$flagSet = 0;
		}elseif(isset($payload["int64"])){
			$flagSet = $payload["int64"]["value"];
		}else{
			throw new \InvalidArgumentException("Wrong type found for flags, want long, but have " . array_key_first($payload));
		}

		if((($flagSet >> $realFlagId) & 1) !== ($value ? 1 : 0)){
			$flagSet ^= (1 << $realFlagId);
			$this->setLong($propertyId, $flagSet);
		}
	}

	public function setPlayerFlag(int $flagId, bool $value) : void{
		$payload = ($this->properties[EntityMetadataProperties::PLAYER_FLAGS] ?? null)["payload"] ?? null;
		if($payload === null){
			$flagSet = 0;
		}elseif(isset($payload["byte"])){
			$flagSet = $payload["byte"]["value"];
		}else{
			throw new \InvalidArgumentException("Wrong type found for flags, want byte, but have " . array_key_first($payload));
		}
		if((($flagSet >> $flagId) & 1) !== ($value ? 1 : 0)){
			$flagSet ^= (1 << $flagId);
			$this->setByte(EntityMetadataProperties::PLAYER_FLAGS, $flagSet);
		}
	}

	/**
	 * Returns every property, in the order they were first set.
	 *
	 * @phpstan-return list<DataItemEntry>
	 */
	public function getAll() : array{
		return array_values($this->properties);
	}

	/**
	 * Returns the properties that changed since clearDirtyProperties() and need to be sent.
	 *
	 * @phpstan-return list<DataItemEntry>
	 */
	public function getDirty() : array{
		return array_values($this->dirtyProperties);
	}

	public function hasDirty() : bool{
		return $this->dirtyProperties !== [];
	}

	public function clearDirtyProperties() : void{
		$this->dirtyProperties = [];
	}

	public function markDirty(int $key) : void{
		if(isset($this->properties[$key])){
			$this->dirtyProperties[$key] = $this->properties[$key];
		}
	}

	public function markAllDirty() : void{
		$this->dirtyProperties = $this->properties;
	}
}
