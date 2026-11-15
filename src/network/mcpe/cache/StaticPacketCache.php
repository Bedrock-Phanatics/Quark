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

use Bedwire\Nbt;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\AvailableActorIdentifiersPacket;
use pocketmine\network\mcpe\protocol\BiomeDefinitionListPacket;
use pocketmine\network\mcpe\protocol\JigsawStructureDataPacket;
use pocketmine\network\mcpe\protocol\types\biome\BiomeDefinitionEntry;
use pocketmine\network\mcpe\protocol\types\BlockPaletteEntry;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\SerializableVoxelCells;
use pocketmine\network\mcpe\protocol\types\SerializableVoxelShape;
use pocketmine\network\mcpe\protocol\VoxelShapesPacket;
use quark\block\custom\BlockRegistry;
use quark\color\Color;
use quark\data\bedrock\BedrockDataFiles;
use quark\data\SavedDataLoadingException;
use quark\network\mcpe\cache\model\VoxelShapesData;
use quark\network\mcpe\protocol\EncodedPacket;
use quark\utils\Filesystem;
use quark\utils\SingletonTrait;
use quark\utils\Utils;
use quark\world\biome\model\BiomeDefinitionEntryData;
use function count;
use function get_debug_type;
use function is_array;
use function json_decode;

/**
 * Packets that are the same for every player, encoded once.
 */
class StaticPacketCache{
	use SingletonTrait;

	private static function loadCompoundFromFile(string $filePath) : CompoundTag{
		return Nbt::read(Filesystem::fileGetContents($filePath), Nbt::NETWORK)->mustGetCompoundTag();
	}

	/**
	 * @return list<BiomeDefinitionEntry>
	 */
	private static function loadBiomeDefinitionModel(string $filePath) : array{
		$biomeEntries = json_decode(Filesystem::fileGetContents($filePath), associative: true);
		if(!is_array($biomeEntries)){
			throw new SavedDataLoadingException("$filePath root should be an array, got " . get_debug_type($biomeEntries));
		}

		$jsonMapper = new \JsonMapper();
		$jsonMapper->bExceptionOnMissingData = true;
		$jsonMapper->bStrictObjectTypeChecking = true;
		$jsonMapper->bEnforceMapType = false;

		$entries = [];
		foreach(Utils::promoteKeys($biomeEntries) as $biomeName => $entry){
			if(!is_array($entry)){
				throw new SavedDataLoadingException("$filePath should be an array of objects, got " . get_debug_type($entry));
			}

			try{
				$biomeDefinition = $jsonMapper->map($entry, new BiomeDefinitionEntryData());

				$mapWaterColour = $biomeDefinition->mapWaterColour;
				$entries[] = new BiomeDefinitionEntry(
					(string) $biomeName,
					$biomeDefinition->id,
					$biomeDefinition->temperature,
					$biomeDefinition->downfall,
					$biomeDefinition->foliageSnow,
					$biomeDefinition->depth,
					$biomeDefinition->scale,
					new Color(
						$mapWaterColour->r,
						$mapWaterColour->g,
						$mapWaterColour->b,
						$mapWaterColour->a
					),
					$biomeDefinition->rain,
					count($biomeDefinition->tags) > 0 ? $biomeDefinition->tags : null,
				);
			}catch(\JsonMapper_Exception $e){
				throw new \RuntimeException($e->getMessage(), 0, $e);
			}
		}

		return $entries;
	}

	private static function buildVoxelShapesPacket(string $filePath) : VoxelShapesPacket{
		$voxelShapes = json_decode(Filesystem::fileGetContents($filePath), associative: true);
		if(!is_array($voxelShapes)){
			throw new SavedDataLoadingException("$filePath root should be an array, got " . get_debug_type($voxelShapes));
		}

		$jsonMapper = new \JsonMapper();
		$jsonMapper->bExceptionOnMissingData = true;
		$jsonMapper->bStrictObjectTypeChecking = true;
		$jsonMapper->bEnforceMapType = false;

		try{
			$data = $jsonMapper->map($voxelShapes, new VoxelShapesData());
		}catch(\JsonMapper_Exception $e){
			throw new \RuntimeException($e->getMessage(), 0, $e);
		}

		$shapes = [];
		foreach($data->shapes as $shape){
			$cells = $shape->cells;
			$shapes[] = new SerializableVoxelShape(
				new SerializableVoxelCells($cells->xSize, $cells->ySize, $cells->zSize, $cells->storage),
				$shape->x,
				$shape->y,
				$shape->z
			);
		}

		return VoxelShapesPacket::create($shapes, $data->nameMap, 0);
	}

	/** @phpstan-return list<BlockPaletteEntry> */
	private static function loadDataDrivenBlockPalette(string $filePath) : array{
		$paletteNBT = self::loadCompoundFromFile($filePath)->getListTag("blockPalette");
		if($paletteNBT === null){
			throw new SavedDataLoadingException("$filePath should contain a blockPalette ListTag");
		}
		$palette = [];
		foreach($paletteNBT->getValue() as $entryNBT){
			if(!$entryNBT instanceof CompoundTag){
				throw new SavedDataLoadingException("$filePath blockPalette should contain CompoundTag entries, got " . get_debug_type($entryNBT));
			}
			$name = $entryNBT->getString("name");
			$states = $entryNBT->getCompoundTag("states");
			if($states === null){
				throw new SavedDataLoadingException("$filePath blockPalette entry $name should contain a states CompoundTag");
			}
			$palette[] = new BlockPaletteEntry($name, new CacheableNbt($states));
		}
		return $palette;
	}

	private static function make() : self{
		return new self(
			EncodedPacket::of(BiomeDefinitionListPacket::fromDefinitions(self::loadBiomeDefinitionModel(BedrockDataFiles::BIOME_DEFINITIONS_JSON))),
			EncodedPacket::of(AvailableActorIdentifiersPacket::create(new CacheableNbt(self::loadCompoundFromFile(BedrockDataFiles::ENTITY_IDENTIFIERS_NBT)))),
			EncodedPacket::of(JigsawStructureDataPacket::create(new CacheableNbt(self::loadCompoundFromFile(BedrockDataFiles::JIGSAW_STRUCTURES_DATA_NBT)))),
			EncodedPacket::of(self::buildVoxelShapesPacket(BedrockDataFiles::VOXEL_SHAPES_JSON)),
			[...self::loadDataDrivenBlockPalette(BedrockDataFiles::DATA_DRIVEN_BLOCKS_NBT), ...BlockRegistry::getInstance()->getPaletteEntries()]
		);
	}

	/** @phpstan-param list<BlockPaletteEntry> $blockPaletteEntries */
	public function __construct(
		private EncodedPacket $biomeDefs,
		private EncodedPacket $availableActorIdentifiers,
		private EncodedPacket $jigsawStructureData,
		private EncodedPacket $voxelShapes,
		private array $blockPaletteEntries
	){
	}

	public function getBiomeDefs() : EncodedPacket{
		return $this->biomeDefs;
	}

	public function getAvailableActorIdentifiers() : EncodedPacket{
		return $this->availableActorIdentifiers;
	}

	public function getJigsawStructureData() : EncodedPacket{
		return $this->jigsawStructureData;
	}

	public function getVoxelShapes() : EncodedPacket{
		return $this->voxelShapes;
	}

	/**
	 * The data-driven blocks StartGame lists, as its block_properties field takes them.
	 *
	 * @phpstan-return list<BlockPaletteEntry>
	 */
	public function getBlockPaletteEntries() : array{
		return $this->blockPaletteEntries;
	}
}
