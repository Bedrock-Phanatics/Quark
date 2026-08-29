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

use quark\data\bedrock\item\SavedItemData;
use quark\item\Item;
use quark\world\format\io\GlobalItemDataHandlers;

class PotionContainerChangeRecipe implements BrewingRecipe{

	public function __construct(
		private string $inputItemId,
		private RecipeIngredient $ingredient,
		private string $outputItemId
	){}

	public function getInputItemId() : string{
		return $this->inputItemId;
	}

	public function getIngredient() : RecipeIngredient{
		return $this->ingredient;
	}

	public function getOutputItemId() : string{
		return $this->outputItemId;
	}

	public function getResultFor(Item $input) : ?Item{
		//TODO: this is a really awful hack, but there isn't another way for now
		//this relies on transforming the serialized item's ID, relying on the target item type's data being the same as the input.
		//This is the same assumption previously made using ItemFactory::get(), except it was less obvious how bad it was.
		//The other way is to bake the actual Potion class types into here, which isn't great for data-driving stuff.
		//We need a better solution for this.

		$data = GlobalItemDataHandlers::getSerializer()->serializeType($input);
		return $data->getName() === $this->getInputItemId() ?
			GlobalItemDataHandlers::getDeserializer()->deserializeType(new SavedItemData($this->getOutputItemId(), $data->getMeta(), $data->getBlock(), $data->getTag())) :
			null;
	}
}
