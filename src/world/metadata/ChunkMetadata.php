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

namespace quark\world\metadata;

use quark\utils\Utils;
use function array_keys;
use function count;
use function crc32;
use function implode;
use function ord;
use function pack;
use function str_contains;
use function strlen;
use function strstr;
use function substr;
use function unpack;

/**
 * Raw metadata of one chunk, or of a world: values for the whole chunk and values per block, keyed by
 * {@see blockHash()}. Values stay encoded, so data of keys nobody registered is kept as is.
 * Block values are grouped by key, which keeps a chunk where one key covers many blocks small.
 *
 * Stored little-endian as: u8 version, u32 purge epoch, u32 key count, then per key u8 name length, name, u8 flags,
 * u8 has chunk value, u32 block count, [u16 length, chunk value], (u32 block hash, u16 length, value)*, and last a
 * CRC32 of everything before it. Block hashes are part of the format, so their packing must never change.
 *
 * @internal
 */
final class ChunkMetadata{
	public const MAX_VALUE_BYTES = 0xffff;
	/** Bounds what one chunk record can grow to, since it is read and written whole. */
	public const MAX_BYTES = 1 << 20;

	private const VERSION = 1;
	private const ENTRY_OVERHEAD = 8;

	/**
	 * Flags last stored with each key name.
	 * @var int[]
	 * @phpstan-var array<string, int>
	 */
	private array $flags = [];
	/**
	 * @var string[]
	 * @phpstan-var array<string, string>
	 */
	private array $values = [];
	/**
	 * Key name => block hash => value, never holding an empty map.
	 * @var string[][]
	 * @phpstan-var array<string, array<int, string>>
	 */
	private array $blocks = [];
	private int $bytes = 0;
	private int $changes = 0;
	private int $savedChanges = 0;

	/**
	 * @param int $epoch the world's purge epoch this data is up to date with, see {@see WorldMetadata::removeNamespace()}
	 */
	public function __construct(public int $epoch = 0){}

	public function isEmpty() : bool{
		return count($this->values) === 0 && count($this->blocks) === 0;
	}

	public function isDirty() : bool{
		return $this->changes !== $this->savedChanges;
	}

	/** Returns a mark to pass to {@see markSaved()} once what is encoded now is stored. */
	public function getChanges() : int{
		return $this->changes;
	}

	/** Changes made after $changes was taken keep the data dirty. */
	public function markSaved(int $changes) : void{
		$this->savedChanges = $changes;
	}

	public function markDirty() : void{
		++$this->changes;
	}

	public function getValue(string $name) : ?string{
		return $this->values[$name] ?? null;
	}

	public function setValue(string $name, int $flags, string $value) : void{
		$old = $this->values[$name] ?? null;
		if($old === $value && $this->flags[$name] === $flags){
			return;
		}
		$this->reserve($old, $value);
		$this->values[$name] = $value;
		$this->flags[$name] = $flags;
		++$this->changes;
	}

	public function removeValue(string $name) : bool{
		if(!isset($this->values[$name])){
			return false;
		}
		$this->bytes -= strlen($this->values[$name]) + self::ENTRY_OVERHEAD;
		unset($this->values[$name]);
		++$this->changes;
		return true;
	}

	public function getBlockValue(int $blockHash, string $name) : ?string{
		return $this->blocks[$name][$blockHash] ?? null;
	}

	public function setBlockValue(int $blockHash, string $name, int $flags, string $value) : void{
		$old = $this->blocks[$name][$blockHash] ?? null;
		if($old === $value && $this->flags[$name] === $flags){
			return;
		}
		$this->reserve($old, $value);
		$this->blocks[$name][$blockHash] = $value;
		$this->flags[$name] = $flags;
		++$this->changes;
	}

	public function removeBlockValue(int $blockHash, string $name) : bool{
		if(!isset($this->blocks[$name][$blockHash])){
			return false;
		}
		$this->bytes -= strlen($this->blocks[$name][$blockHash]) + self::ENTRY_OVERHEAD;
		unset($this->blocks[$name][$blockHash]);
		if(count($this->blocks[$name]) === 0){
			unset($this->blocks[$name]);
		}
		++$this->changes;
		return true;
	}

	/**
	 * @return string[] values keyed by name
	 * @phpstan-return array<string, string>
	 */
	public function getBlockValues(int $blockHash) : array{
		$values = [];
		foreach(Utils::stringifyKeys($this->blocks) as $name => $blocks){
			if(isset($blocks[$blockHash])){
				$values[$name] = $blocks[$blockHash];
			}
		}
		return $values;
	}

	public function getFlags(string $name) : int{
		return $this->flags[$name] ?? 0;
	}

	/**
	 * @return int[]
	 * @phpstan-return list<int>
	 */
	public function getBlockHashes() : array{
		$hashes = [];
		foreach($this->blocks as $blocks){
			$hashes += $blocks;
		}
		return array_keys($hashes);
	}

	/**
	 * Applies retention after the block at $blockHash changed state. Costs a lookup per key held by the chunk.
	 */
	public function onBlockChanged(int $blockHash, bool $typeChanged) : void{
		//names always hold a ':', so PHP never turns them into int keys; this runs on every block change in the chunk
		foreach($this->blocks as $name => $blocks){ // @phpstan-ignore pocketmine.foreach.stringKeys
			if(isset($blocks[$blockHash])){
				$retention = MetadataKey::retentionOf($this->flags[$name]);
				if($retention === Retention::BLOCK_STATE || ($typeChanged && $retention === Retention::BLOCK_TYPE)){
					$this->removeBlockValue($blockHash, $name);
				}
			}
		}
	}

	/**
	 * Removes and returns the values that belong to the block rather than its position.
	 *
	 * @return int[][]|string[][] name => [flags, value]
	 * @phpstan-return array<string, array{int, string}>
	 */
	public function takeBlockValues(int $blockHash, bool $copyOnly) : array{
		$taken = [];
		foreach(Utils::stringifyKeys($this->getBlockValues($blockHash)) as $name => $value){
			$flags = $this->flags[$name];
			if(MetadataKey::retentionOf($flags) === Retention::POSITION || ($copyOnly && ($flags & MetadataKey::FLAG_NOT_COPYABLE) !== 0)){
				continue;
			}
			$taken[$name] = [$flags, $value];
			if(!$copyOnly){
				$this->removeBlockValue($blockHash, $name);
			}
		}
		return $taken;
	}

	/**
	 * Replaces the block-bound values of the block with $values, all or nothing.
	 *
	 * @param int[][]|string[][] $values name => [flags, value]
	 * @phpstan-param array<string, array{int, string}> $values
	 */
	public function putBlockValues(int $blockHash, array $values) : void{
		$bytes = $this->bytes;
		foreach(Utils::stringifyKeys($this->getBlockValues($blockHash)) as $name => $value){
			if(isset($values[$name]) || MetadataKey::retentionOf($this->flags[$name]) !== Retention::POSITION){
				$bytes -= strlen($value) + self::ENTRY_OVERHEAD;
			}
		}
		foreach(Utils::stringifyKeys($values) as $name => [$flags, $value]){
			if(strlen($name) > 255 || !str_contains($name, ":") || $flags < 0 || $flags > 0xff){
				throw new \InvalidArgumentException("Invalid metadata key \"$name\" or flags $flags");
			}
			if(strlen($value) > self::MAX_VALUE_BYTES){
				throw new \InvalidArgumentException("Metadata values can't be longer than " . self::MAX_VALUE_BYTES . " bytes, got " . strlen($value));
			}
			$bytes += strlen($value) + self::ENTRY_OVERHEAD;
		}
		if($bytes > self::MAX_BYTES){
			throw new \LengthException("A chunk can't hold more than " . self::MAX_BYTES . " bytes of metadata");
		}
		//only additions remain after these removals, so no step can pass the limit checked above
		$this->takeBlockValues($blockHash, false);
		foreach(Utils::stringifyKeys($values) as $name => $_){
			$this->removeBlockValue($blockHash, $name);
		}
		foreach(Utils::stringifyKeys($values) as $name => [$flags, $value]){
			$this->setBlockValue($blockHash, $name, $flags, $value);
		}
	}

	public function removeNamespace(string $namespace) : bool{
		$removed = false;
		foreach(Utils::stringifyKeys($this->values) as $name => $value){
			if(self::namespaceOf($name) === $namespace){
				$this->bytes -= strlen($value) + self::ENTRY_OVERHEAD;
				unset($this->values[$name]);
				$removed = true;
			}
		}
		foreach(Utils::stringifyKeys($this->blocks) as $name => $blocks){
			if(self::namespaceOf($name) === $namespace){
				foreach($blocks as $value){
					$this->bytes -= strlen($value) + self::ENTRY_OVERHEAD;
				}
				unset($this->blocks[$name]);
				$removed = true;
			}
		}
		if($removed){
			++$this->changes;
		}
		return $removed;
	}

	/**
	 * Packs chunk-local coordinates (any x and z work, only their low 4 bits count) the way records store them.
	 */
	public static function blockHash(int $x, int $y, int $z) : int{
		return ($y << 8) | (($z & 0xf) << 4) | ($x & 0xf);
	}

	public static function namespaceOf(string $name) : string{
		return (string) strstr($name, ":", true);
	}

	private function reserve(?string $old, string $new) : void{
		if(strlen($new) > self::MAX_VALUE_BYTES){
			throw new \InvalidArgumentException("Metadata values can't be longer than " . self::MAX_VALUE_BYTES . " bytes, got " . strlen($new));
		}
		$bytes = $this->bytes + strlen($new) - ($old === null ? -self::ENTRY_OVERHEAD : strlen($old));
		if($bytes > self::MAX_BYTES){
			throw new \LengthException("A chunk can't hold more than " . self::MAX_BYTES . " bytes of metadata");
		}
		$this->bytes = $bytes;
	}

	public function encode() : string{
		$names = $this->values + $this->blocks;
		$parts = [pack("CVV", self::VERSION, $this->epoch, count($names))];
		foreach(Utils::stringifyKeys($names) as $name => $_){
			$value = $this->values[$name] ?? null;
			$blocks = $this->blocks[$name] ?? [];
			$parts[] = pack("C", strlen($name)) . $name . pack("CCV", $this->flags[$name], $value !== null ? 1 : 0, count($blocks));
			if($value !== null){
				$parts[] = pack("v", strlen($value)) . $value;
			}
			foreach($blocks as $blockHash => $blockValue){
				$parts[] = pack("Vv", $blockHash, strlen($blockValue)) . $blockValue;
			}
		}
		$payload = implode("", $parts);
		return $payload . pack("V", crc32($payload));
	}

	/**
	 * @throws MetadataException
	 */
	public static function decode(string $data) : self{
		$length = strlen($data) - 4;
		if($length < 9){
			throw new MetadataException("Metadata record is truncated");
		}
		$payload = substr($data, 0, $length);
		/** @var int[] $header */
		$header = unpack("Vcrc", $data, $length);
		if($header["crc"] !== crc32($payload)){
			throw new MetadataException("Metadata record checksum mismatch");
		}
		/** @var int[] $header */
		$header = unpack("Cversion/Vepoch/Vkeys", $payload);
		if($header["version"] !== self::VERSION){
			throw new MetadataException("Unsupported metadata record version " . $header["version"]);
		}
		$result = new self($header["epoch"]);
		$offset = 9;
		for($i = 0, $keys = $header["keys"]; $i < $keys; ++$i){
			if($offset >= $length){
				throw new MetadataException("Metadata record is truncated");
			}
			$nameLength = ord($payload[$offset++]);
			if($offset + $nameLength + 6 > $length){
				throw new MetadataException("Metadata record is truncated");
			}
			$name = substr($payload, $offset, $nameLength);
			$offset += $nameLength;
			/** @var int[] $key */
			$key = unpack("Cflags/Cchunk/Vblocks", $payload, $offset);
			$offset += 6;
			if(!str_contains($name, ":") || isset($result->flags[$name]) || $key["chunk"] > 1 || ($key["chunk"] === 0 && $key["blocks"] === 0)){
				throw new MetadataException("Invalid or repeated metadata key \"$name\"");
			}
			$result->flags[$name] = $key["flags"];

			if($key["chunk"] === 1){
				if($offset + 2 > $length){
					throw new MetadataException("Metadata record is truncated");
				}
				/** @var int[] $entry */
				$entry = unpack("vlength", $payload, $offset);
				$offset += 2;
				if($offset + $entry["length"] > $length){
					throw new MetadataException("Metadata record is truncated");
				}
				$result->values[$name] = substr($payload, $offset, $entry["length"]);
				$offset += $entry["length"];
				$result->bytes += $entry["length"] + self::ENTRY_OVERHEAD;
			}

			if($key["blocks"] * 6 > $length - $offset){
				throw new MetadataException("Metadata record is truncated");
			}
			$blocks = [];
			for($j = 0, $count = $key["blocks"]; $j < $count; ++$j){
				if($offset + 6 > $length){
					throw new MetadataException("Metadata record is truncated");
				}
				/** @var int[] $entry */
				$entry = unpack("Vhash/vlength", $payload, $offset);
				$offset += 6;
				$blockHash = $entry["hash"] >= 0x80000000 ? $entry["hash"] - 0x100000000 : $entry["hash"];
				if(isset($blocks[$blockHash]) || $offset + $entry["length"] > $length){
					throw new MetadataException("Repeated or truncated metadata value of \"$name\"");
				}
				$blocks[$blockHash] = substr($payload, $offset, $entry["length"]);
				$offset += $entry["length"];
				$result->bytes += $entry["length"] + self::ENTRY_OVERHEAD;
			}
			if(count($blocks) !== 0){
				$result->blocks[$name] = $blocks;
			}
		}
		if($offset !== $length){
			throw new MetadataException("Unexpected data after the metadata record");
		}
		return $result;
	}
}
