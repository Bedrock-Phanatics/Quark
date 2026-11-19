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

use function array_keys;

/**
 * The block-bound metadata of one block, detached from any world, for moving or copying it with the block. Values of
 * keys nobody registered travel too.
 */
final class BlockMetadata{
	private bool $used = false;

	private function __clone(){}

	/**
	 * @internal Get snapshots from {@see WorldMetadata::copyBlock()} and {@see WorldMetadata::takeBlock()}.
	 *
	 * @param int[][]|string[][] $values name => [flags, encoded value]
	 * @phpstan-param non-empty-array<string, array{int, string}> $values
	 */
	public function __construct(
		private readonly array $values,
		private readonly bool $singleUse = false
	){}

	/**
	 * @return int[][]|string[][]
	 * @phpstan-return non-empty-array<string, array{int, string}>
	 */
	public function getValues() : array{
		if($this->used){
			throw new MetadataException("Moved block metadata was already placed");
		}
		return $this->values;
	}

	/** @internal */
	public function markPlaced() : void{
		$this->used = $this->singleUse;
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getNames() : array{
		return array_keys($this->values);
	}

	/**
	 * @phpstan-template T
	 * @phpstan-param MetadataKey<T> $key
	 * @phpstan-return T|null
	 */
	public function get(MetadataKey $key) : mixed{
		$value = $this->values[$key->name][1] ?? null;
		return $value === null ? null : WorldMetadata::decode($key, $value, "a block snapshot");
	}
}
