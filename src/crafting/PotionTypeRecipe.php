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

class PotionTypeRecipe implements BrewingRecipe{

	public function __construct(
		private RecipeIngredient $input,
		private RecipeIngredient $ingredient,
		private Item $output
	){
		$this->output = clone $output;
	}

	public function getInput() : RecipeIngredient{
		return $this->input;
	}

	public function getIngredient() : RecipeIngredient{
		return $this->ingredient;
	}

	public function getOutput() : Item{
		return clone $this->output;
	}

	public function getResultFor(Item $input) : ?Item{
		return $this->input->accepts($input) ? $this->getOutput() : null;
	}
}
