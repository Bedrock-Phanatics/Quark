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

interface CraftingRecipe{
	/**
	 * Returns a list of items needed to craft this recipe. This MUST NOT include Air items or items with a zero count.
	 *
	 * @return RecipeIngredient[]
	 * @phpstan-return list<RecipeIngredient>
	 */
	public function getIngredientList() : array;

	/**
	 * Returns a list of results this recipe will produce when the inputs in the given crafting grid are consumed.
	 *
	 * @return Item[]
	 * @phpstan-return list<Item>
	 */
	public function getResultsFor(CraftingGrid $grid) : array;

	/**
	 * Returns whether the given crafting grid meets the requirements to craft this recipe.
	 */
	public function matchesCraftingGrid(CraftingGrid $grid) : bool;
}
