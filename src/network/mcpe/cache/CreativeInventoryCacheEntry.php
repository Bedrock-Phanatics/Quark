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

namespace quark\network\mcpe\cache;

use pocketmine\network\mcpe\protocol\types\inventory\CreativeItemEntry;
use quark\inventory\CreativeCategory;
use quark\inventory\CreativeGroup;

final class CreativeInventoryCacheEntry{

	/**
	 * @param CreativeCategory[]     $categories
	 * @param CreativeGroup[]|null[] $groups
	 * @param CreativeItemEntry[]    $items
	 *
	 * @phpstan-param list<CreativeCategory>   $categories
	 * @phpstan-param list<CreativeGroup|null> $groups
	 * @phpstan-param list<CreativeItemEntry>  $items
	 */
	public function __construct(
		public readonly array $categories,
		public readonly array $groups,
		public readonly array $items,
	){
		//NOOP
	}
}
