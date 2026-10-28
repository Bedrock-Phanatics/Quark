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
use quark\network\mcpe\protocol\types\DataItemBytePayload;
use quark\network\mcpe\protocol\types\DataItemCompoundTagPayload;
use quark\network\mcpe\protocol\types\DataItemEntry;
use quark\network\mcpe\protocol\types\DataItemEntryPayload;
use quark\network\mcpe\protocol\types\DataItemFloatPayload;
use quark\network\mcpe\protocol\types\DataItemInt64Payload;
use quark\network\mcpe\protocol\types\DataItemIntPayload;
use quark\network\mcpe\protocol\types\DataItemPosPayload;
use quark\network\mcpe\protocol\types\DataItemShortPayload;
use quark\network\mcpe\protocol\types\DataItemStringPayload;
use quark\network\mcpe\protocol\types\DataItemVec3Payload;
use function array_fill_keys;
use function array_keys;
use function array_values;
use function is_int;

/**
 * An entity's network properties, kept as the actor_data entries they are sent as, and the ones that changed since
 * they were last sent. Entries are replaced rather than changed, so the lists handed out stay valid.
 *
 * @phpstan-type Payload DataItemBytePayload|DataItemShortPayload|DataItemIntPayload|DataItemFloatPayload|DataItemStringPayload|DataItemCompoundTagPayload|DataItemPosPayload|DataItemInt64Payload|DataItemVec3Payload
 */
final class EntityMetadata{
	private const NAMES = [
		EntityMetadataTypes::BYTE => "byte",
		EntityMetadataTypes::SHORT => "short",
		EntityMetadataTypes::INT => "int",
		EntityMetadataTypes::FLOAT => "float",
		EntityMetadataTypes::STRING => "string",
		EntityMetadataTypes::COMPOUND_TAG => "compound_tag",
		EntityMetadataTypes::POS => "pos",
		EntityMetadataTypes::LONG => "int64",
		EntityMetadataTypes::VECTOR3F => "vec3",
	];

	/** @phpstan-var array<int, DataItemEntry> by property ID, in the order they were first set */
	private array $entries = [];
	/** @phpstan-var list<DataItemEntry>|null */
	private ?array $all = null;
	/** @phpstan-var array<int, true> */
	private array $dirty = [];

	private static function checkRange(int $value, int $min, int $max) : void{
		if($value < $min || $value > $max){
			throw new \InvalidArgumentException("Value $value is out of range $min - $max");
		}
	}

	public function setByte(int $key, int $value, bool $force = false) : void{
		self::checkRange($value, -0x80, 0x7f);
		if($this->changes($key, EntityMetadataTypes::BYTE, $value, $force)){
			$this->put($key, new DataItemBytePayload(EntityMetadataTypes::BYTE, $value));
		}
	}

	public function setShort(int $key, int $value, bool $force = false) : void{
		self::checkRange($value, -0x8000, 0x7fff);
		if($this->changes($key, EntityMetadataTypes::SHORT, $value, $force)){
			$this->put($key, new DataItemShortPayload(EntityMetadataTypes::SHORT, $value));
		}
	}

	public function setInt(int $key, int $value, bool $force = false) : void{
		self::checkRange($value, -0x80000000, 0x7fffffff);
		if($this->changes($key, EntityMetadataTypes::INT, $value, $force)){
			$this->put($key, new DataItemIntPayload(EntityMetadataTypes::INT, $value));
		}
	}

	public function setFloat(int $key, float $value, bool $force = false) : void{
		if($this->changes($key, EntityMetadataTypes::FLOAT, $value, $force)){
			$this->put($key, new DataItemFloatPayload(EntityMetadataTypes::FLOAT, $value));
		}
	}

	public function setString(int $key, string $value, bool $force = false) : void{
		if($this->changes($key, EntityMetadataTypes::STRING, $value, $force)){
			$this->put($key, new DataItemStringPayload(EntityMetadataTypes::STRING, $value));
		}
	}

	/**
	 * @param string $nbt a compound tag in the network NBT encoding
	 */
	public function setCompoundTag(int $key, string $nbt, bool $force = false) : void{
		if($this->changes($key, EntityMetadataTypes::COMPOUND_TAG, $nbt, $force)){
			$this->put($key, new DataItemCompoundTagPayload(EntityMetadataTypes::COMPOUND_TAG, $nbt));
		}
	}

	public function setBlockPos(int $key, ?Vector3 $value, bool $force = false) : void{
		$value = $value?->floor() ?? new Vector3(0, 0, 0);
		if($this->changes($key, EntityMetadataTypes::POS, $value, $force)){
			$this->put($key, new DataItemPosPayload(EntityMetadataTypes::POS, $value));
		}
	}

	public function setLong(int $key, int $value, bool $force = false) : void{
		if($this->changes($key, EntityMetadataTypes::LONG, $value, $force)){
			$this->put($key, new DataItemInt64Payload(EntityMetadataTypes::LONG, $value));
		}
	}

	public function setVector3(int $key, ?Vector3 $value, bool $force = false) : void{
		$value ??= new Vector3(0.0, 0.0, 0.0);
		if($this->changes($key, EntityMetadataTypes::VECTOR3F, $value, $force)){
			$this->put($key, new DataItemVec3Payload(EntityMetadataTypes::VECTOR3F, $value->asVector3()));
		}
	}

	/**
	 * @phpstan-return Payload|null
	 */
	private function payload(int $key) : ?DataItemEntryPayload{
		/** @phpstan-var Payload|null $payload */
		$payload = ($this->entries[$key] ?? null)?->payload;
		return $payload;
	}

	private function changes(int $key, int $type, int|float|string|Vector3 $value, bool $force) : bool{
		$current = $this->payload($key);
		if($current === null){
			return true;
		}
		if($current->type !== $type){
			if(!$force){
				throw new \InvalidArgumentException("Can't overwrite property $key of type " . self::NAMES[$current->type] . " with " . self::NAMES[$type]);
			}
			return true;
		}
		$old = $current->value;
		return $value instanceof Vector3 ? !($old instanceof Vector3 && $old->equals($value)) : $old !== $value;
	}

	private function put(int $key, DataItemEntryPayload $payload) : void{
		$this->entries[$key] = new DataItemEntry($key, $payload);
		$this->all = null;
		$this->dirty[$key] = true;
	}

	private function flags(int $key, int $type) : int{
		$current = $this->payload($key);
		if($current === null){
			return 0;
		}
		if($current->type !== $type || !is_int($current->value)){
			throw new \InvalidArgumentException("Wrong type found for flags, want " . self::NAMES[$type] . ", but have " . self::NAMES[$current->type]);
		}
		return $current->value;
	}

	public function setGenericFlag(int $flagId, bool $value) : void{
		$propertyId = $flagId >= 64 ? EntityMetadataProperties::FLAGS2 : EntityMetadataProperties::FLAGS;
		$realFlagId = $flagId % 64;
		$flagSet = $this->flags($propertyId, EntityMetadataTypes::LONG);
		if((($flagSet >> $realFlagId) & 1) !== ($value ? 1 : 0)){
			$flagSet ^= (1 << $realFlagId);
			$this->setLong($propertyId, $flagSet);
		}
	}

	public function setPlayerFlag(int $flagId, bool $value) : void{
		$flagSet = $this->flags(EntityMetadataProperties::PLAYER_FLAGS, EntityMetadataTypes::BYTE);
		if((($flagSet >> $flagId) & 1) !== ($value ? 1 : 0)){
			$flagSet ^= (1 << $flagId);
			$this->setByte(EntityMetadataProperties::PLAYER_FLAGS, $flagSet);
		}
	}

	public function get(int $key) : ?DataItemEntry{
		return $this->entries[$key] ?? null;
	}

	/**
	 * Returns every property, in the order they were first set.
	 *
	 * @phpstan-return list<DataItemEntry>
	 */
	public function getAll() : array{
		return $this->all ??= array_values($this->entries);
	}

	/**
	 * Returns the properties that changed since clearDirtyProperties() and need to be sent.
	 *
	 * @phpstan-return list<DataItemEntry>
	 */
	public function getDirty() : array{
		$dirty = [];
		foreach($this->dirty as $key => $_){
			$dirty[] = $this->entries[$key];
		}
		return $dirty;
	}

	public function hasDirty() : bool{
		return $this->dirty !== [];
	}

	public function clearDirtyProperties() : void{
		$this->dirty = [];
	}

	public function markDirty(int $key) : void{
		if(isset($this->entries[$key])){
			$this->dirty[$key] = true;
		}
	}

	public function markAllDirty() : void{
		$this->dirty = array_fill_keys(array_keys($this->entries), true);
	}
}
