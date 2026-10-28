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
use quark\network\mcpe\protocol\types\DataItemFloatPayload;
use quark\network\mcpe\protocol\types\DataItemInt64Payload;
use quark\network\mcpe\protocol\types\DataItemIntPayload;
use quark\network\mcpe\protocol\types\DataItemPosPayload;
use quark\network\mcpe\protocol\types\DataItemShortPayload;
use quark\network\mcpe\protocol\types\DataItemStringPayload;
use quark\network\mcpe\protocol\types\DataItemVec3Payload;
use quark\utils\AssumptionFailedError;
use function array_fill_keys;
use function array_keys;
use function is_float;
use function is_int;
use function is_string;

/**
 * An entity's network properties and the ones that changed since they were last sent. Properties are kept as flat
 * type and value maps, since every entity holds one of these, and only become actor_data entries when sent.
 */
final class EntityMetadata{
	private const VARIANTS = [
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

	/** @phpstan-var array<int, int> property ID => EntityMetadataTypes constant */
	private array $types = [];
	/** @phpstan-var array<int, int|float|string|Vector3> */
	private array $values = [];
	/** @phpstan-var array<int, true> */
	private array $dirty = [];

	private static function checkRange(int $value, int $min, int $max) : void{
		if($value < $min || $value > $max){
			throw new \InvalidArgumentException("Value $value is out of range $min - $max");
		}
	}

	public function setByte(int $key, int $value, bool $force = false) : void{
		self::checkRange($value, -0x80, 0x7f);
		$this->set($key, EntityMetadataTypes::BYTE, $value, $force);
	}

	public function setShort(int $key, int $value, bool $force = false) : void{
		self::checkRange($value, -0x8000, 0x7fff);
		$this->set($key, EntityMetadataTypes::SHORT, $value, $force);
	}

	public function setInt(int $key, int $value, bool $force = false) : void{
		self::checkRange($value, -0x80000000, 0x7fffffff);
		$this->set($key, EntityMetadataTypes::INT, $value, $force);
	}

	public function setFloat(int $key, float $value, bool $force = false) : void{
		$this->set($key, EntityMetadataTypes::FLOAT, $value, $force);
	}

	public function setString(int $key, string $value, bool $force = false) : void{
		$this->set($key, EntityMetadataTypes::STRING, $value, $force);
	}

	/**
	 * @param string $nbt a compound tag in the network NBT encoding
	 */
	public function setCompoundTag(int $key, string $nbt, bool $force = false) : void{
		$this->set($key, EntityMetadataTypes::COMPOUND_TAG, $nbt, $force);
	}

	public function setBlockPos(int $key, ?Vector3 $value, bool $force = false) : void{
		$this->set($key, EntityMetadataTypes::POS, $value?->floor() ?? new Vector3(0, 0, 0), $force);
	}

	public function setLong(int $key, int $value, bool $force = false) : void{
		$this->set($key, EntityMetadataTypes::LONG, $value, $force);
	}

	public function setVector3(int $key, ?Vector3 $value, bool $force = false) : void{
		$this->set($key, EntityMetadataTypes::VECTOR3F, $value?->asVector3() ?? new Vector3(0.0, 0.0, 0.0), $force);
	}

	private function set(int $key, int $type, int|float|string|Vector3 $value, bool $force) : void{
		$currentType = $this->types[$key] ?? null;
		if($currentType !== null){
			if(!$force && $currentType !== $type){
				throw new \InvalidArgumentException("Can't overwrite property $key of type " . self::VARIANTS[$currentType] . " with " . self::VARIANTS[$type]);
			}
			$current = $this->values[$key];
			if($currentType === $type && ($value instanceof Vector3 ? $current instanceof Vector3 && $current->equals($value) : $current === $value)){
				return;
			}
		}
		$this->types[$key] = $type;
		$this->values[$key] = $value;
		$this->dirty[$key] = true;
	}

	private function flags(int $key, int $type) : int{
		$currentType = $this->types[$key] ?? null;
		if($currentType === null){
			return 0;
		}
		$value = $this->values[$key];
		if($currentType !== $type || !is_int($value)){
			throw new \InvalidArgumentException("Wrong type found for flags, want " . self::VARIANTS[$type] . ", but have " . self::VARIANTS[$currentType]);
		}
		return $value;
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

	/**
	 * @phpstan-param iterable<int, mixed> $keys
	 * @phpstan-return list<DataItemEntry>
	 */
	private function entries(iterable $keys) : array{
		$entries = [];
		foreach($keys as $key => $_){
			$type = $this->types[$key];
			$value = $this->values[$key];
			$entries[] = new DataItemEntry($key, match(true){
				$type === EntityMetadataTypes::BYTE && is_int($value) => ["byte" => new DataItemBytePayload($type, $value)],
				$type === EntityMetadataTypes::SHORT && is_int($value) => ["short" => new DataItemShortPayload($type, $value)],
				$type === EntityMetadataTypes::INT && is_int($value) => ["int" => new DataItemIntPayload($type, $value)],
				$type === EntityMetadataTypes::FLOAT && is_float($value) => ["float" => new DataItemFloatPayload($type, $value)],
				$type === EntityMetadataTypes::STRING && is_string($value) => ["string" => new DataItemStringPayload($type, $value)],
				$type === EntityMetadataTypes::COMPOUND_TAG && is_string($value) => ["compound_tag" => new DataItemCompoundTagPayload($type, $value)],
				$type === EntityMetadataTypes::POS && $value instanceof Vector3 => ["pos" => new DataItemPosPayload($type, $value)],
				$type === EntityMetadataTypes::LONG && is_int($value) => ["int64" => new DataItemInt64Payload($type, $value)],
				$type === EntityMetadataTypes::VECTOR3F && $value instanceof Vector3 => ["vec3" => new DataItemVec3Payload($type, $value)],
				default => throw new AssumptionFailedError("Property $key holds the wrong value for its type"),
			});
		}
		return $entries;
	}

	/**
	 * Returns every property, in the order they were first set.
	 *
	 * @phpstan-return list<DataItemEntry>
	 */
	public function getAll() : array{
		return $this->entries($this->types);
	}

	/**
	 * Returns the properties that changed since clearDirtyProperties() and need to be sent.
	 *
	 * @phpstan-return list<DataItemEntry>
	 */
	public function getDirty() : array{
		return $this->entries($this->dirty);
	}

	public function hasDirty() : bool{
		return $this->dirty !== [];
	}

	public function clearDirtyProperties() : void{
		$this->dirty = [];
	}

	public function markDirty(int $key) : void{
		if(isset($this->types[$key])){
			$this->dirty[$key] = true;
		}
	}

	public function markAllDirty() : void{
		$this->dirty = array_fill_keys(array_keys($this->types), true);
	}
}
