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

namespace quark\network\mcpe\protocol\types;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class ShapedRecipePayload{
	/**
	 * @param list<RecipeIngredientRecipeIngredient> $ingredients
	 * @param list<NetworkItemInstanceDescriptor> $results
	 */
	public function __construct(
		public string $recipeId,
		public int $width,
		public int $height,
		public array $ingredients,
		public array $results,
		public string $uuid,
		public string $tag,
		public int $priority,
		public bool $assumeSymmetry,
		public ?RecipeUnlockRequirementSerializedData $unlockingRequirement,
		public int $netId,
	){}
}
