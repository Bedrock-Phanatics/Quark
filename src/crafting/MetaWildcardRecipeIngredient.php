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

namespace quark\crafting;

use quark\item\Item;
use quark\world\format\io\GlobalItemDataHandlers;

/**
 * Recipe ingredient that matches items by their Minecraft ID only. This is used for things like the crafting table
 * recipe from planks (multiple types of planks are accepted).
 *
 * WARNING: Plugins shouldn't usually use this. This is a hack that relies on internal Minecraft behaviour, which might
 * change or break at any time.
 *
 * @internal
 */
final class MetaWildcardRecipeIngredient implements RecipeIngredient{

	public function __construct(
		private string $itemId,
	){}

	public function getItemId() : string{ return $this->itemId; }

	public function accepts(Item $item) : bool{
		if($item->getCount() < 1){
			return false;
		}

		return GlobalItemDataHandlers::getSerializer()->serializeType($item)->getName() === $this->itemId;
	}

	public function __toString() : string{
		return "MetaWildcardRecipeIngredient($this->itemId)";
	}
}
