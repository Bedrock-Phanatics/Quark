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

namespace quark\network\mcpe\protocol\packet;

use Bedwire\PacketId;
use quark\network\mcpe\protocol\Packet;
use quark\network\mcpe\protocol\types\ContainerMixDataEntry;
use quark\network\mcpe\protocol\types\MaterialReducerDataEntry;
use quark\network\mcpe\protocol\types\MultiRecipePayload;
use quark\network\mcpe\protocol\types\PotionMixDataEntry;
use quark\network\mcpe\protocol\types\ShapedRecipePayload;
use quark\network\mcpe\protocol\types\ShapelessRecipePayload;
use quark\network\mcpe\protocol\types\SmithingTransformRecipePayload;
use quark\network\mcpe\protocol\types\SmithingTrimRecipePayload;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class CraftingDataPacket extends Packet{
	public const int ID = PacketId::CRAFTING_DATA;

	/**
	 * @param list<ShapedRecipePayload> $shapedRecipes
	 * @param list<ShapelessRecipePayload> $shapelessRecipes
	 * @param list<MultiRecipePayload> $multiRecipes
	 * @param list<ShapelessRecipePayload> $userDataShapelessRecipes
	 * @param list<ShapelessRecipePayload> $shapelessChemistryRecipes
	 * @param list<ShapedRecipePayload> $shapedChemistryRecipes
	 * @param list<SmithingTransformRecipePayload> $smithingTransformRecipes
	 * @param list<SmithingTrimRecipePayload> $smithingTrimRecipes
	 * @param list<PotionMixDataEntry> $potionMixes
	 * @param list<ContainerMixDataEntry> $containerMixes
	 * @param list<MaterialReducerDataEntry> $materialReducers
	 */
	public function __construct(
		public array $shapedRecipes,
		public array $shapelessRecipes,
		public array $multiRecipes,
		public array $userDataShapelessRecipes,
		public array $shapelessChemistryRecipes,
		public array $shapedChemistryRecipes,
		public array $smithingTransformRecipes,
		public array $smithingTrimRecipes,
		public array $potionMixes,
		public array $containerMixes,
		public array $materialReducers,
		public bool $clearRecipes,
	){}

	public function getId() : int{
		return self::ID;
	}
}
