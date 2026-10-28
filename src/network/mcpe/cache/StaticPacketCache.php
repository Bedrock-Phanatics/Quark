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
use pocketmine\color\Color;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\utils\Binary;
use quark\data\bedrock\BedrockDataFiles;
use quark\data\SavedDataLoadingException;
use quark\network\mcpe\cache\model\VoxelShapesData;
use quark\network\mcpe\protocol\EncodedPacket;
use quark\network\mcpe\protocol\Packet;
use quark\network\mcpe\protocol\packet\AvailableActorIdentifiersPacket;
use quark\network\mcpe\protocol\packet\BiomeDefinitionListPacket;
use quark\network\mcpe\protocol\packet\JigsawStructureDataPacket;
use quark\network\mcpe\protocol\packet\VoxelShapesPacket;
use quark\network\mcpe\protocol\types\BiomeDefinition;
use quark\network\mcpe\protocol\types\PacketMapOfBiomeNamesToDataEntry;
use quark\network\mcpe\protocol\types\PacketNameMapEntry;
use quark\network\mcpe\protocol\types\ServerBlockProperty;
use quark\network\mcpe\protocol\types\VoxelCells;
use quark\network\mcpe\protocol\types\VoxelShape;
use quark\utils\Filesystem;
use quark\utils\SingletonTrait;
use quark\utils\Utils;
use quark\world\biome\model\BiomeDefinitionEntryData;
use function array_map;
use function count;
use function get_debug_type;
use function is_array;
use function json_decode;
use function pack;

/**
 * Packets that are the same for every player, encoded once.
 */
class StaticPacketCache{
	use SingletonTrait;

	private static function loadCompoundFromFile(string $filePath) : CompoundTag{
		return Nbt::read(Filesystem::fileGetContents($filePath), Nbt::NETWORK)->mustGetCompoundTag();
	}

	private static function encodeCompound(CompoundTag $tag) : string{
		return Nbt::write(new TreeRoot($tag), Nbt::NETWORK);
	}

	private static function buildBiomeDefinitions(string $filePath) : EncodedPacket{
		$biomeEntries = json_decode(Filesystem::fileGetContents($filePath), associative: true);
		if(!is_array($biomeEntries)){
			throw new SavedDataLoadingException("$filePath root should be an array, got " . get_debug_type($biomeEntries));
		}

		$jsonMapper = new \JsonMapper();
		$jsonMapper->bExceptionOnMissingData = true;
		$jsonMapper->bStrictObjectTypeChecking = true;
		$jsonMapper->bEnforceMapType = false;

		//biome names and tags are sent once in a string table, and referred to by index
		$stringIndexes = [];
		$strings = [];
		$addString = function(string $string) use (&$stringIndexes, &$strings) : int{
			if(!isset($stringIndexes[$string])){
				$stringIndexes[$string] = count($strings);
				$strings[] = $string;
			}
			return $stringIndexes[$string];
		};

		$definitions = [];
		foreach(Utils::promoteKeys($biomeEntries) as $biomeName => $entry){
			if(!is_array($entry)){
				throw new SavedDataLoadingException("$filePath should be an array of objects, got " . get_debug_type($entry));
			}

			try{
				$biomeDefinition = $jsonMapper->map($entry, new BiomeDefinitionEntryData());
			}catch(\JsonMapper_Exception $e){
				throw new \RuntimeException($e->getMessage(), 0, $e);
			}
			$colour = $biomeDefinition->mapWaterColour;
			$definitions[] = new PacketMapOfBiomeNamesToDataEntry(
				key: $addString((string) $biomeName),
				value: new BiomeDefinition(
					id: $biomeDefinition->id,
					temperature: $biomeDefinition->temperature,
					downfall: $biomeDefinition->downfall,
					foliageSnow: $biomeDefinition->foliageSnow,
					depth: $biomeDefinition->depth,
					scale: $biomeDefinition->scale,
					mapWaterColorArgb: Binary::signInt((new Color($colour->r, $colour->g, $colour->b, $colour->a))->toARGB()),
					rain: $biomeDefinition->rain,
					tags: count($biomeDefinition->tags) > 0 ? array_map($addString, $biomeDefinition->tags) : null,
				),
			);
		}

		return self::encoded(new BiomeDefinitionListPacket(mapOfBiomeNamesToData: $definitions, stringList: $strings));
	}

	private static function buildVoxelShapes(string $filePath) : EncodedPacket{
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
			$shapes[] = new VoxelShape(
				cells: new VoxelCells(xSize: $cells->xSize, ySize: $cells->ySize, zSize: $cells->zSize, storage: pack("C*", ...$cells->storage)),
				xCoordinates: $shape->x,
				yCoordinates: $shape->y,
				zCoordinates: $shape->z,
			);
		}
		$nameMap = [];
		foreach(Utils::stringifyKeys($data->nameMap) as $name => $id){
			$nameMap[] = new PacketNameMapEntry(key: $name, value: $id);
		}

		return self::encoded(new VoxelShapesPacket(shapes: $shapes, nameMap: $nameMap, customShapeCount: 0));
	}

	/** @phpstan-return list<ServerBlockProperty> */
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
			$palette[] = new ServerBlockProperty(blockName: $name, blockDefinition: self::encodeCompound($states));
		}
		return $palette;
	}

	/**
	 * Encodes the packet now, so every session sends the same bytes without encoding them again.
	 */
	private static function encoded(Packet $packet) : EncodedPacket{
		return EncodedPacket::of($packet);
	}

	private static function make() : self{
		return new self(
			self::buildBiomeDefinitions(BedrockDataFiles::BIOME_DEFINITIONS_JSON),
			self::encoded(new AvailableActorIdentifiersPacket(identifierList: self::encodeCompound(self::loadCompoundFromFile(BedrockDataFiles::ENTITY_IDENTIFIERS_NBT)))),
			self::encoded(new JigsawStructureDataPacket(jigsawStructureDataTag: self::encodeCompound(self::loadCompoundFromFile(BedrockDataFiles::JIGSAW_STRUCTURES_DATA_NBT)))),
			self::buildVoxelShapes(BedrockDataFiles::VOXEL_SHAPES_JSON),
			self::loadDataDrivenBlockPalette(BedrockDataFiles::DATA_DRIVEN_BLOCKS_NBT)
		);
	}

	/** @phpstan-param list<ServerBlockProperty> $blockPaletteEntries */
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
	 * @phpstan-return list<ServerBlockProperty>
	 */
	public function getBlockPaletteEntries() : array{
		return $this->blockPaletteEntries;
	}
}
