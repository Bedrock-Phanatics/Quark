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

use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use function pack;
use function strlen;
use function unpack;

/**
 * Turns values into the bytes that are stored and back. Decoding should throw on bytes it doesn't accept.
 *
 * @template T
 */
final class MetadataCodec{

	/**
	 * @phpstan-param \Closure(T) : string $encode
	 * @phpstan-param \Closure(string) : T $decode
	 */
	public function __construct(
		public readonly \Closure $encode,
		public readonly \Closure $decode
	){}

	/** @return self<string> */
	public static function string() : self{
		return new self(static fn(string $value) : string => $value, static fn(string $bytes) : string => $bytes);
	}

	/** @return self<int> */
	public static function int() : self{
		return new self(static fn(int $value) : string => pack("P", $value), static function(string $bytes) : int{
			if(strlen($bytes) !== 8){
				throw new \UnexpectedValueException("Expected 8 bytes, got " . strlen($bytes));
			}
			/** @var int[] $value */
			$value = unpack("P", $bytes);
			return $value[1];
		});
	}

	/** @return self<float> */
	public static function float() : self{
		return new self(static fn(float $value) : string => pack("e", $value), static function(string $bytes) : float{
			if(strlen($bytes) !== 8){
				throw new \UnexpectedValueException("Expected 8 bytes, got " . strlen($bytes));
			}
			/** @var float[] $value */
			$value = unpack("e", $bytes);
			return $value[1];
		});
	}

	/** @return self<bool> */
	public static function bool() : self{
		return new self(static fn(bool $value) : string => $value ? "\x01" : "\x00", static fn(string $bytes) : bool => match($bytes){
			"\x01" => true,
			"\x00" => false,
			default => throw new \UnexpectedValueException("Expected a single 0 or 1 byte")
		});
	}

	/**
	 * Structured data as little-endian NBT.
	 *
	 * @return self<CompoundTag>
	 */
	public static function compound() : self{
		return new self(
			static fn(CompoundTag $value) : string => (new LittleEndianNbtSerializer())->write(new TreeRoot($value)),
			static function(string $bytes) : CompoundTag{
				$offset = 0;
				$tag = (new LittleEndianNbtSerializer())->read($bytes, $offset, 64)->mustGetCompoundTag();
				if($offset !== strlen($bytes)){
					throw new \UnexpectedValueException("Unexpected data after the compound tag");
				}
				return $tag;
			}
		);
	}
}
