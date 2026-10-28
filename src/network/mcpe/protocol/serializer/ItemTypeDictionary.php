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

namespace quark\network\mcpe\protocol\serializer;

use quark\network\mcpe\protocol\types\ItemTypeEntry;
use function array_key_exists;

final class ItemTypeDictionary{

	/**
	 * @var string[]
	 * @phpstan-var array<int, string>
	 */
	private array $intToStringIdMap = [];
	/**
	 * @var int[]
	 * @phpstan-var array<string, int>
	 */
	private array $stringToIntMap = [];

	/**
	 * @param ItemTypeEntry[] $itemTypes
	 * @phpstan-param list<ItemTypeEntry> $itemTypes
	 */
	public function __construct(private array $itemTypes){
		foreach($this->itemTypes as $type){
			$this->stringToIntMap[$type->getStringId()] = $type->getNumericId();
			$this->intToStringIdMap[$type->getNumericId()] = $type->getStringId();
		}
	}

	/**
	 * @return ItemTypeEntry[]
	 * @phpstan-return list<ItemTypeEntry>
	 */
	public function getEntries() : array{
		return $this->itemTypes;
	}

	public function fromStringId(string $stringId) : int{
		if(!array_key_exists($stringId, $this->stringToIntMap)){
			throw new \InvalidArgumentException("Unmapped string ID \"$stringId\"");
		}
		return $this->stringToIntMap[$stringId];
	}

	public function fromIntId(int $intId) : string{
		if(!array_key_exists($intId, $this->intToStringIdMap)){
			throw new \InvalidArgumentException("Unmapped int ID $intId");
		}
		return $this->intToStringIdMap[$intId];
	}
}
