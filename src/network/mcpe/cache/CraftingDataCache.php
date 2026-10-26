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

use pocketmine\network\mcpe\protocol\types\recipe\CraftingRecipeBlockName;
use pocketmine\network\mcpe\protocol\types\recipe\FurnaceRecipeBlockName;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeUnlockingContext;
use pocketmine\network\mcpe\protocol\types\recipe\StringIdMetaItemDescriptor;
use quark\crafting\CraftingManager;
use quark\crafting\FurnaceType;
use quark\crafting\RecipeIngredient;
use quark\crafting\ShapedRecipe;
use quark\crafting\ShapelessRecipe;
use quark\crafting\ShapelessRecipeType;
use quark\item\Item;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\protocol\Fields;
use quark\network\mcpe\protocol\Packet;
use quark\network\mcpe\protocol\Packets;
use quark\pulse\internal\PulseZones;
use quark\utils\AssumptionFailedError;
use quark\utils\SingletonTrait;
use Ramsey\Uuid\Uuid;
use function array_map;
use function spl_object_id;

/**
 * @phpstan-import-type NetworkItemInstanceDescriptor from Packets
 */
final class CraftingDataCache{
	use SingletonTrait;

	/**
	 * @var Packet[]
	 * @phpstan-var array<int, Packet>
	 */
	private array $caches = [];

	/**
	 * The client doesn't like recipes with ID 0 (as of 1.21.100) and complains about them in the content log
	 * This doesn't actually affect the function of the recipe, but it is annoying, so this offset fixes it
	 */
	public const RECIPE_ID_OFFSET = 1;

	public function getCache(CraftingManager $manager) : Packet{
		$id = spl_object_id($manager);
		if(!isset($this->caches[$id])){
			$manager->getDestructorCallbacks()->add(function() use ($id) : void{
				unset($this->caches[$id]);
			});
			$manager->getRecipeRegisteredCallbacks()->add(function() use ($id) : void{
				unset($this->caches[$id]);
			});
			$this->caches[$id] = $this->buildCraftingDataCache($manager);
		}
		return $this->caches[$id];
	}

	/**
	 * Rebuilds the cached crafting data packet.
	 */
	private function buildCraftingDataCache(CraftingManager $manager) : Packet{
		$craftingDataCacheRebuildScope = PulseZones::$craftingDataCacheRebuild->start();

		$nullUUID = Fields::uuid(Uuid::fromString(Uuid::NIL));
		$converter = TypeConverter::getInstance();
		$ingredient = fn(?RecipeIngredient $ingredient) => Fields::recipeIngredient($converter->coreRecipeIngredientToNet($ingredient));
		/** @phpstan-var \Closure(Item) : NetworkItemInstanceDescriptor $result */
		$result = fn(Item $item) => Fields::itemInstance($converter->coreItemStackToNet($item));

		$noUnlockingRequirement = ["unlocking_context" => RecipeUnlockingContext::ALWAYS_UNLOCKED->value, "unlocking_ingredients" => null];
		$recipeNetId = self::RECIPE_ID_OFFSET;
		$shapelessRecipes = [];
		$shapedRecipes = [];

		foreach($manager->getCraftingRecipeIndex() as $index => $recipe){
			//the client doesn't like recipes with an ID of 0, so we need to offset them
			$recipeNetId = $index + self::RECIPE_ID_OFFSET;
			if($recipe instanceof ShapelessRecipe){
				$typeTag = match($recipe->getType()){
					ShapelessRecipeType::CRAFTING => CraftingRecipeBlockName::CRAFTING_TABLE,
					ShapelessRecipeType::STONECUTTER => CraftingRecipeBlockName::STONECUTTER,
					ShapelessRecipeType::CARTOGRAPHY => CraftingRecipeBlockName::CARTOGRAPHY_TABLE,
					ShapelessRecipeType::SMITHING => CraftingRecipeBlockName::SMITHING_TABLE,
				};
				$shapelessRecipes[] = [
					"recipe_id" => Uuid::uuid4()->toString(),
					"ingredients" => array_map($ingredient, $recipe->getIngredientList()),
					"results" => array_map($result, $recipe->getResults()),
					"uuid" => $nullUUID,
					"tag" => $typeTag,
					"priority" => 50,
					"unlocking_requirement" => $noUnlockingRequirement,
					"net_id" => $recipeNetId,
				];
			}elseif($recipe instanceof ShapedRecipe){
				$inputs = [];
				for($row = 0, $height = $recipe->getHeight(); $row < $height; ++$row){
					for($column = 0, $width = $recipe->getWidth(); $column < $width; ++$column){
						$inputs[] = $ingredient($recipe->getIngredient($column, $row));
					}
				}
				$shapedRecipes[] = [
					"recipe_id" => Uuid::uuid4()->toString(),
					"width" => $recipe->getWidth(),
					"height" => $recipe->getHeight(),
					"ingredients" => $inputs,
					"results" => array_map($result, $recipe->getResults()),
					"uuid" => $nullUUID,
					"tag" => CraftingRecipeBlockName::CRAFTING_TABLE,
					"priority" => 50,
					"assume_symmetry" => true,
					"unlocking_requirement" => $noUnlockingRequirement,
					"net_id" => $recipeNetId,
				];
			}else{
				//TODO: probably special recipe types
			}
		}

		foreach(FurnaceType::cases() as $furnaceType){
			$typeTag = match($furnaceType){
				FurnaceType::FURNACE => FurnaceRecipeBlockName::FURNACE,
				FurnaceType::BLAST_FURNACE => FurnaceRecipeBlockName::BLAST_FURNACE,
				FurnaceType::SMOKER => FurnaceRecipeBlockName::SMOKER,
				FurnaceType::CAMPFIRE => FurnaceRecipeBlockName::CAMPFIRE,
				FurnaceType::SOUL_CAMPFIRE => FurnaceRecipeBlockName::SOUL_CAMPFIRE
			};
			foreach($manager->getFurnaceRecipeManager($furnaceType)->getAll() as $recipe){
				$recipeNetId++;
				$shapelessRecipes[] = [
					"recipe_id" => Uuid::uuid4()->toString(),
					"ingredients" => [$ingredient($recipe->getInput())],
					"results" => [$result($recipe->getResult())],
					"uuid" => $nullUUID,
					"tag" => $typeTag,
					"priority" => 50,
					"unlocking_requirement" => $noUnlockingRequirement,
					"net_id" => $recipeNetId, //not used, but we need to fill them with something unique regardless
				];
			}
		}

		$potionTypeRecipes = [];
		$itemTypeDictionary = $converter->getItemTypeDictionary();
		foreach($manager->getPotionTypeRecipes() as $recipe){
			$input = $converter->coreRecipeIngredientToNet($recipe->getInput())->getDescriptor();
			$reagent = $converter->coreRecipeIngredientToNet($recipe->getIngredient())->getDescriptor();
			if(!$input instanceof StringIdMetaItemDescriptor || !$reagent instanceof StringIdMetaItemDescriptor){
				throw new AssumptionFailedError();
			}
			$output = $converter->coreItemStackToNet($recipe->getOutput());
			$potionTypeRecipes[] = [
				"from_potion_id" => $itemTypeDictionary->fromStringId($input->getId()),
				"from_item_aux" => $input->getMeta(),
				"reagent_item_id" => $itemTypeDictionary->fromStringId($reagent->getId()),
				"reagent_item_aux" => $reagent->getMeta(),
				"to_potion_id" => $output->getId(),
				"to_item_aux" => $output->getMeta(),
			];
		}

		$potionContainerChangeRecipes = [];
		foreach($manager->getPotionContainerChangeRecipes() as $recipe){
			$reagent = $converter->coreRecipeIngredientToNet($recipe->getIngredient())->getDescriptor();
			if(!$reagent instanceof StringIdMetaItemDescriptor){
				throw new AssumptionFailedError();
			}
			$potionContainerChangeRecipes[] = [
				"from_item_id" => $itemTypeDictionary->fromStringId($recipe->getInputItemId()),
				"reagent_item_id" => $itemTypeDictionary->fromStringId($reagent->getId()),
				"to_item_id" => $itemTypeDictionary->fromStringId($recipe->getOutputItemId()),
			];
		}

		$packet = Packets::craftingData([
			"shaped_recipes" => $shapedRecipes,
			"shapeless_recipes" => $shapelessRecipes,
			"multi_recipes" => [],
			"user_data_shapeless_recipes" => [],
			"shapeless_chemistry_recipes" => [],
			"shaped_chemistry_recipes" => [],
			"smithing_transform_recipes" => [],
			"smithing_trim_recipes" => [],
			"potion_mixes" => $potionTypeRecipes,
			"container_mixes" => $potionContainerChangeRecipes,
			"material_reducers" => [],
			"clear_recipes" => true,
		]);
		//every player gets the same bytes, so encode them once
		$packet->getEncoded();
		PulseZones::$craftingDataCacheRebuild->stop($craftingDataCacheRebuildScope);
		return $packet;
	}
}
