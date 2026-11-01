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

namespace quark\world\format\io\leveldb;

use Bedwire\Nbt as NativeNbt;
use Bedwire\NbtException;

use pocketmine\nbt\NBT;
use pocketmine\nbt\NbtDataException;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;
use pocketmine\world\format\PalettedBlockArray;
use quark\block\Block;
use quark\data\bedrock\BiomeIds;
use quark\data\bedrock\block\BlockStateDeserializeException;
use quark\data\bedrock\block\convert\UnsupportedBlockStateException;
use quark\data\bedrock\WorldDataVersions;
use quark\utils\Utils;
use quark\world\format\Chunk;
use quark\world\format\io\BaseWorldProvider;
use quark\world\format\io\ChunkData;
use quark\world\format\io\ChunkUtils;
use quark\world\format\io\data\BedrockWorldData;
use quark\world\format\io\exception\CorruptedChunkException;
use quark\world\format\io\exception\CorruptedWorldException;
use quark\world\format\io\GlobalBlockStateHandlers;
use quark\world\format\io\LoadedChunkData;
use quark\world\format\io\WorldData;
use quark\world\format\io\WritableWorldProvider;
use quark\world\format\SubChunk;
use Symfony\Component\Filesystem\Path;
use function array_map;
use function array_values;
use function chr;
use function count;
use function implode;
use function ord;
use function str_repeat;
use function substr;
use function unpack;

/**
 * Bedrock's chunk record format, independent of the database the records are stored in.
 */
abstract class BedrockWorldProvider extends BaseWorldProvider implements WritableWorldProvider{

	protected const FINALISATION_NEEDS_INSTATICKING = 0;
	protected const FINALISATION_NEEDS_POPULATION = 1;
	protected const FINALISATION_DONE = 2;

	protected const ENTRY_FLAT_WORLD_LAYERS = "game_flatworldlayers";

	protected const CURRENT_LEVEL_CHUNK_VERSION = WorldDataVersions::CHUNK;
	protected const CURRENT_LEVEL_SUBCHUNK_VERSION = WorldDataVersions::SUBCHUNK;

	private const CAVES_CLIFFS_EXPERIMENTAL_SUBCHUNK_KEY_OFFSET = 4;

	private const PALETTE_CACHE_SIZE = 16384;

	/**
	 * Block states already decoded from raw palette NBT. Only successful decodes are kept, since they cannot change
	 * while the server runs.
	 * @phpstan-var array<string, int>
	 */
	private array $paletteStateIds = [];
	/**
	 * Palette NBT per state ID, bounded by the block state registry.
	 * @phpstan-var array<int, string>
	 */
	private array $paletteEncodings = [];

	protected function loadLevelData() : WorldData{
		return new BedrockWorldData(Path::join($this->getPath(), "level.dat"));
	}

	public function getWorldMinY() : int{
		return -64;
	}

	public function getWorldMaxY() : int{
		return 320;
	}

	/**
	 * @throws CorruptedChunkException
	 */
	protected function deserializeBlockPalette(BinaryStream $stream, \Logger $logger) : PalettedBlockArray{
		$bitsPerBlock = $stream->getByte() >> 1;

		try{
			$words = $stream->get(PalettedBlockArray::getExpectedWordArraySize($bitsPerBlock));
		}catch(\InvalidArgumentException $e){
			throw new CorruptedChunkException("Failed to deserialize paletted storage: " . $e->getMessage(), 0, $e);
		}
		$palette = [];

		if($bitsPerBlock === 0){
			$paletteSize = 1;
			/*
			 * Due to code copy-paste in a public plugin, some PM4 worlds have 0 bpb palettes with a length prefix.
			 * This is invalid and does not happen in vanilla.
			 * These palettes were accepted by PM4 despite being invalid, but PM5 considered them corrupt, causing loss
			 * of data. Since many users were affected by this, a workaround is therefore necessary to allow PM5 to read
			 * these worlds without data loss.
			 *
			 * References:
			 * - https://github.com/Refaltor77/CustomItemAPI/issues/68
			 * - https://github.com/Bedrock-Phanatics/Quark/issues/5911
			 */
			$offset = $stream->getOffset();
			$byte1 = $stream->getByte();
			$stream->setOffset($offset); //reset offset

			if($byte1 !== NBT::TAG_Compound){ //normally the first byte would be the NBT of the blockstate
				$susLength = $stream->getLInt();
				if($susLength !== 1){ //make sure the data isn't complete garbage
					throw new CorruptedChunkException("CustomItemAPI borked 0 bpb palette should always have a length of 1");
				}
				$logger->error("Unexpected palette size for 0 bpb palette");
			}
		}else{
			$paletteSize = $stream->getLInt();
		}

		$blockDecodeErrors = [];
		$buffer = $stream->getBuffer();

		for($i = 0; $i < $paletteSize; ++$i){
			$offset = $stream->getOffset();
			try{
				$length = NativeNbt::length($buffer, NativeNbt::BEDROCK, $offset);
				$rawState = substr($buffer, $offset, $length);
				$stream->setOffset($offset + $length);
				$stateId = $this->paletteStateIds[$rawState] ?? null;
				if($stateId !== null){
					$palette[] = $stateId;
					continue;
				}
				$blockStateNbt = NativeNbt::read($rawState, NativeNbt::BEDROCK)->mustGetCompoundTag();
			}catch(NbtException|NbtDataException $e){
				//NBT borked, unrecoverable
				throw new CorruptedChunkException("Invalid blockstate NBT at offset $i in paletted storage: " . $e->getMessage(), 0, $e);
			}

			//TODO: remember data for unknown states so we can implement them later
			try{
				$blockStateData = $this->blockDataUpgrader->upgradeBlockStateNbt($blockStateNbt);
			}catch(BlockStateDeserializeException $e){
				//while not ideal, this is not a fatal error
				$errorMessage = "Upgrade error: " . $e->getMessage() . ", NBT: " . $blockStateNbt->toString();
				$blockDecodeErrors[$errorMessage][] = $i;
				$palette[] = $this->blockStateDeserializer->deserialize(GlobalBlockStateHandlers::getUnknownBlockStateData());
				continue;
			}
			try{
				$stateId = $this->blockStateDeserializer->deserialize($blockStateData);
				if(count($this->paletteStateIds) >= self::PALETTE_CACHE_SIZE){
					$this->paletteStateIds = [];
				}
				$this->paletteStateIds[$rawState] = $stateId;
				$palette[] = $stateId;
			}catch(UnsupportedBlockStateException $e){
				$blockDecodeErrors[$e->getMessage()][] = $i;
				$palette[] = $this->blockStateDeserializer->deserialize(GlobalBlockStateHandlers::getUnknownBlockStateData());
			}catch(BlockStateDeserializeException $e){
				$errorMessage = "Deserialize error: " . $e->getMessage() . ", NBT: " . $blockStateNbt->toString();
				$blockDecodeErrors[$errorMessage][] = $i;
				$palette[] = $this->blockStateDeserializer->deserialize(GlobalBlockStateHandlers::getUnknownBlockStateData());
			}
		}

		if(count($blockDecodeErrors) > 0){
			$finalErrors = [];
			foreach(Utils::promoteKeys($blockDecodeErrors) as $errorMessage => $paletteOffsets){
				$finalErrors[] = "$errorMessage (palette offsets: " . implode(", ", $paletteOffsets) . ")";
			}
			$logger->error("Errors decoding blocks:\n - " . implode("\n - ", $finalErrors));
		}

		//TODO: exceptions
		return PalettedBlockArray::fromData($bitsPerBlock, $words, $palette);
	}

	private function serializeBlockPalette(BinaryStream $stream, PalettedBlockArray $blocks) : void{
		$stream->putByte($blocks->getBitsPerBlock() << 1);
		$stream->put($blocks->getWordArray());

		$palette = $blocks->getPalette();
		if($blocks->getBitsPerBlock() !== 0){
			$stream->putLInt(count($palette));
		}
		foreach($palette as $p){
			$stream->put($this->paletteEncodings[$p] ??= NativeNbt::write(new TreeRoot($this->blockStateSerializer->serialize($p)->toNbt()), NativeNbt::BEDROCK));
		}
	}

	/**
	 * @throws CorruptedChunkException
	 */
	private static function getExpected3dBiomesCount(int $chunkVersion) : int{
		return match(true){
			$chunkVersion >= ChunkVersion::v1_18_30 => 24,
			$chunkVersion >= ChunkVersion::v1_18_0_25_beta => 25,
			$chunkVersion >= ChunkVersion::v1_18_0_24_beta => 32,
			$chunkVersion >= ChunkVersion::v1_18_0_22_beta => 65,
			$chunkVersion >= ChunkVersion::v1_17_40_20_beta_experimental_caves_cliffs => 32,
			default => throw new CorruptedChunkException("Chunk version $chunkVersion should not have 3D biomes")
		};
	}

	/**
	 * @throws CorruptedChunkException
	 */
	private static function deserializeBiomePalette(BinaryStream $stream, int $bitsPerBlock) : PalettedBlockArray{
		try{
			$words = $stream->get(PalettedBlockArray::getExpectedWordArraySize($bitsPerBlock));
		}catch(\InvalidArgumentException $e){
			throw new CorruptedChunkException("Failed to deserialize paletted biomes: " . $e->getMessage(), 0, $e);
		}
		$palette = [];
		$paletteSize = $bitsPerBlock === 0 ? 1 : $stream->getLInt();

		for($i = 0; $i < $paletteSize; ++$i){
			$palette[] = $stream->getLInt();
		}

		//TODO: exceptions
		return PalettedBlockArray::fromData($bitsPerBlock, $words, $palette);
	}

	private static function serializeBiomePalette(BinaryStream $stream, PalettedBlockArray $biomes) : void{
		$stream->putByte($biomes->getBitsPerBlock() << 1);
		$stream->put($biomes->getWordArray());

		$palette = $biomes->getPalette();
		if($biomes->getBitsPerBlock() !== 0){
			$stream->putLInt(count($palette));
		}
		foreach($palette as $p){
			$stream->putLInt($p);
		}
	}

	/**
	 * @throws CorruptedChunkException
	 * @return PalettedBlockArray[]
	 * @phpstan-return array<int, PalettedBlockArray>
	 */
	private static function deserialize3dBiomes(BinaryStream $stream, int $chunkVersion, \Logger $logger) : array{
		$previous = null;
		$result = [];
		$nextIndex = Chunk::MIN_SUBCHUNK_INDEX;

		$expectedCount = self::getExpected3dBiomesCount($chunkVersion);
		for($i = 0; $i < $expectedCount; ++$i){
			try{
				$bitsPerBlock = $stream->getByte() >> 1;
				if($bitsPerBlock === 127){
					if($previous === null){
						throw new CorruptedChunkException("Serialized biome palette $i has no previous palette to copy from");
					}
					$decoded = clone $previous;
				}else{
					$decoded = self::deserializeBiomePalette($stream, $bitsPerBlock);
				}
				$previous = $decoded;
				if($nextIndex <= Chunk::MAX_SUBCHUNK_INDEX){ //older versions wrote additional superfluous biome palettes
					$result[$nextIndex++] = $decoded;
				}elseif($stream->feof()){
					//not enough padding biome arrays for the given version - this is non-critical since we discard the excess anyway, but this should be logged
					$logger->error("Wrong number of 3D biome palettes for this chunk version: expected $expectedCount, but got " . ($i + 1) . " - this is not a problem, but may indicate a corrupted chunk");
					break;
				}
			}catch(BinaryDataException $e){
				throw new CorruptedChunkException("Failed to deserialize biome palette $i: " . $e->getMessage(), 0, $e);
			}
		}

		return $result;
	}

	/**
	 * @param SubChunk[] $subChunks
	 */
	private static function serialize3dBiomes(BinaryStream $stream, array $subChunks) : void{
		//TODO: the server-side min/max may not coincide with the world storage min/max - we may need additional logic to handle this
		for($y = Chunk::MIN_SUBCHUNK_INDEX; $y <= Chunk::MAX_SUBCHUNK_INDEX; $y++){
			//TODO: is it worth trying to use the previous palette if it's the same as the current one? vanilla supports
			//this, but it's not clear if it's worth the effort to implement.
			self::serializeBiomePalette($stream, $subChunks[$y]->getBiomeArray());
		}
	}

	/**
	 * @phpstan-param-out int $x
	 * @phpstan-param-out int $y
	 * @phpstan-param-out int $z
	 */
	protected static function deserializeExtraDataKey(int $chunkVersion, int $key, ?int &$x, ?int &$y, ?int &$z) : void{
		if($chunkVersion >= ChunkVersion::v1_0_0){
			$x = ($key >> 12) & 0xf;
			$z = ($key >> 8) & 0xf;
			$y = $key & 0xff;
		}else{ //pre-1.0, 7 bits were used because the build height limit was lower
			$x = ($key >> 11) & 0xf;
			$z = ($key >> 7) & 0xf;
			$y = $key & 0x7f;
		}
	}

	/**
	 * @return PalettedBlockArray[]
	 */
	protected function deserializeLegacyExtraData(string $extraRawData, int $chunkVersion, \Logger $logger) : array{
		if($extraRawData === ""){
			return [];
		}

		/** @var PalettedBlockArray[] $extraDataLayers */
		$extraDataLayers = [];
		$binaryStream = new BinaryStream($extraRawData);
		$count = $binaryStream->getLInt();

		for($i = 0; $i < $count; ++$i){
			$key = $binaryStream->getLInt();
			$value = $binaryStream->getLShort();

			self::deserializeExtraDataKey($chunkVersion, $key, $x, $fullY, $z);

			$ySub = ($fullY >> SubChunk::COORD_BIT_SIZE);
			$y = $key & SubChunk::COORD_MASK;

			$blockId = $value & 0xff;
			$blockData = ($value >> 8) & 0xf;
			try{
				$blockStateData = $this->blockDataUpgrader->upgradeIntIdMeta($blockId, $blockData);
			}catch(BlockStateDeserializeException $e){
				//TODO: we could preserve this in case it's supported in the future, but this was historically only
				//used for grass anyway, so we probably don't need to care
				$logger->error("Failed to upgrade legacy extra block: " . $e->getMessage() . " ($blockId:$blockData)");
				continue;
			}
			//assume this won't throw
			$blockStateId = $this->blockStateDeserializer->deserialize($blockStateData);

			if(!isset($extraDataLayers[$ySub])){
				$extraDataLayers[$ySub] = new PalettedBlockArray(Block::EMPTY_STATE_ID);
			}
			$extraDataLayers[$ySub]->set($x, $y, $z, $blockStateId);
		}

		return $extraDataLayers;
	}

	/**
	 * Returns every overworld record of the chunk, keyed by the suffix that follows the chunk index in Bedrock's LevelDB
	 * keys: the record tag, plus the Y byte for subchunks.
	 *
	 * @return string[]
	 * @phpstan-return array<array-key, string>
	 */
	abstract protected function readChunkRecords(int $chunkX, int $chunkZ) : array;

	/**
	 * Puts every string and deletes every null in $records, keyed like {@see readChunkRecords()}, atomically.
	 *
	 * @param string[]|null[] $records
	 * @phpstan-param array<array-key, string|null> $records
	 */
	abstract protected function writeChunkRecords(int $chunkX, int $chunkZ, array $records) : void;

	/**
	 * @param string[] $records
	 * @phpstan-param array<array-key, string> $records
	 */
	private static function readVersion(array $records) : ?int{
		$chunkVersionRaw = $records[ChunkDataKey::NEW_VERSION] ?? $records[ChunkDataKey::OLD_VERSION] ?? null;
		return $chunkVersionRaw !== null ? ord($chunkVersionRaw) : null;
	}

	/**
	 * Deserializes terrain data stored in the 0.9 full-chunk format into subchunks.
	 *
	 * @param string[] $records
	 * @phpstan-param array<array-key, string> $records
	 *
	 * @return SubChunk[]
	 * @phpstan-return array<int, SubChunk>
	 * @throws CorruptedWorldException
	 */
	private function deserializeLegacyTerrainData(array $records, int $chunkVersion, \Logger $logger) : array{
		$convertedLegacyExtraData = $this->deserializeLegacyExtraData($records[ChunkDataKey::LEGACY_BLOCK_EXTRA_DATA] ?? "", $chunkVersion, $logger);

		$legacyTerrain = $records[ChunkDataKey::LEGACY_TERRAIN] ?? null;
		if($legacyTerrain === null){
			throw new CorruptedChunkException("Missing expected LEGACY_TERRAIN tag for format version $chunkVersion");
		}
		$binaryStream = new BinaryStream($legacyTerrain);
		try{
			$fullIds = $binaryStream->get(32768);
			$fullData = $binaryStream->get(16384);
			$binaryStream->get(32768); //legacy light info, discard it
		}catch(BinaryDataException $e){
			throw new CorruptedChunkException($e->getMessage(), 0, $e);
		}

		try{
			$binaryStream->get(256); //heightmap, discard it
			/** @var int[] $unpackedBiomeArray */
			$unpackedBiomeArray = unpack("N*", $binaryStream->get(1024)); //unpack() will never fail here
			$biomes3d = ChunkUtils::extrapolate3DBiomes(ChunkUtils::convertBiomeColors(array_values($unpackedBiomeArray))); //never throws
		}catch(BinaryDataException $e){
			throw new CorruptedChunkException($e->getMessage(), 0, $e);
		}
		if(!$binaryStream->feof()){
			$logger->error("Unexpected trailing data in legacy terrain data");
		}

		$subChunks = [];
		for($yy = 0; $yy < 8; ++$yy){
			$storages = [$this->palettizeLegacySubChunkFromColumn($fullIds, $fullData, $yy, new \quark\log\SourceLogger($logger, "Subchunk y=$yy"))];
			if(isset($convertedLegacyExtraData[$yy])){
				$storages[] = $convertedLegacyExtraData[$yy];
			}
			$subChunks[$yy] = new SubChunk(Block::EMPTY_STATE_ID, $storages, clone $biomes3d);
		}

		//make sure extrapolated biomes get filled in correctly
		for($yy = Chunk::MIN_SUBCHUNK_INDEX; $yy <= Chunk::MAX_SUBCHUNK_INDEX; ++$yy){
			if(!isset($subChunks[$yy])){
				$subChunks[$yy] = new SubChunk(Block::EMPTY_STATE_ID, [], clone $biomes3d);
			}
		}

		return $subChunks;
	}

	/**
	 * Deserializes a subchunk stored in the legacy non-paletted format used from 1.0 until 1.2.13.
	 */
	private function deserializeNonPalettedSubChunkData(BinaryStream $binaryStream, int $chunkVersion, ?PalettedBlockArray $convertedLegacyExtraData, PalettedBlockArray $biomePalette, \Logger $logger) : SubChunk{
		try{
			$blocks = $binaryStream->get(4096);
			$blockData = $binaryStream->get(2048);
		}catch(BinaryDataException $e){
			throw new CorruptedChunkException($e->getMessage(), 0, $e);
		}

		if($chunkVersion < ChunkVersion::v1_1_0){
			try{
				$binaryStream->get(4096); //legacy light info, discard it
				if(!$binaryStream->feof()){
					$logger->error("Unexpected trailing data in legacy subchunk data");
				}
			}catch(BinaryDataException $e){
				$logger->error("Failed to read legacy subchunk light info: " . $e->getMessage());
			}
		}

		$storages = [$this->palettizeLegacySubChunkXZY($blocks, $blockData, $logger)];
		if($convertedLegacyExtraData !== null){
			$storages[] = $convertedLegacyExtraData;
		}

		return new SubChunk(Block::EMPTY_STATE_ID, $storages, $biomePalette);
	}

	/**
	 * Deserializes subchunk data stored under a subchunk LevelDB key.
	 *
	 * @see ChunkDataKey::SUBCHUNK
	 * @throws CorruptedChunkException
	 */
	private function deserializeSubChunkData(BinaryStream $binaryStream, int $chunkVersion, int $subChunkVersion, ?PalettedBlockArray $convertedLegacyExtraData, PalettedBlockArray $biomePalette, \Logger $logger) : SubChunk{
		switch($subChunkVersion){
			case SubChunkVersion::CLASSIC:
			case SubChunkVersion::CLASSIC_BUG_2: //these are all identical to version 0, but vanilla respects these so we should also
			case SubChunkVersion::CLASSIC_BUG_3:
			case SubChunkVersion::CLASSIC_BUG_4:
			case SubChunkVersion::CLASSIC_BUG_5:
			case SubChunkVersion::CLASSIC_BUG_6:
			case SubChunkVersion::CLASSIC_BUG_7:
				return $this->deserializeNonPalettedSubChunkData($binaryStream, $chunkVersion, $convertedLegacyExtraData, $biomePalette, $logger);
			case SubChunkVersion::PALETTED_SINGLE:
				$storages = [$this->deserializeBlockPalette($binaryStream, $logger)];
				if($convertedLegacyExtraData !== null){
					$storages[] = $convertedLegacyExtraData;
				}
				return new SubChunk(Block::EMPTY_STATE_ID, $storages, $biomePalette);
			case SubChunkVersion::PALETTED_MULTI:
			case SubChunkVersion::PALETTED_MULTI_WITH_OFFSET:
				//legacy extradata layers intentionally ignored because they aren't supposed to exist in v8

				$storageCount = $binaryStream->getByte();
				if($subChunkVersion >= SubChunkVersion::PALETTED_MULTI_WITH_OFFSET){
					//height ignored; this seems pointless since this is already in the key anyway
					$binaryStream->getByte();
				}

				$storages = [];
				for($k = 0; $k < $storageCount; ++$k){
					$storages[] = $this->deserializeBlockPalette($binaryStream, $logger);
				}
				return new SubChunk(Block::EMPTY_STATE_ID, $storages, $biomePalette);
			default:
				//this should never happen - an unsupported chunk appearing in a supported world is a sign of corruption
				throw new CorruptedChunkException("don't know how to decode LevelDB subchunk format version $subChunkVersion");
		}
	}

	private static function hasOffsetCavesAndCliffsSubChunks(int $chunkVersion) : bool{
		return $chunkVersion >= ChunkVersion::v1_16_220_50_unused && $chunkVersion <= ChunkVersion::v1_16_230_50_unused;
	}

	/**
	 * Deserializes any subchunks stored under subchunk LevelDB keys, upgrading them to the current format if necessary.
	 *
	 * @param string[]             $records
	 * @param PalettedBlockArray[] $convertedLegacyExtraData
	 * @param PalettedBlockArray[] $biomeArrays
	 *
	 * @phpstan-param array<array-key, string>       $records
	 * @phpstan-param array<int, PalettedBlockArray> $convertedLegacyExtraData
	 * @phpstan-param array<int, PalettedBlockArray> $biomeArrays
	 * @phpstan-param-out bool                       $hasBeenUpgraded
	 *
	 * @return SubChunk[]
	 * @phpstan-return array<int, SubChunk>
	 */
	private function deserializeAllSubChunkData(array $records, int $chunkVersion, bool &$hasBeenUpgraded, array $convertedLegacyExtraData, array $biomeArrays, \Logger $logger) : array{
		$subChunks = [];

		$subChunkKeyOffset = self::hasOffsetCavesAndCliffsSubChunks($chunkVersion) ? self::CAVES_CLIFFS_EXPERIMENTAL_SUBCHUNK_KEY_OFFSET : 0;
		for($y = Chunk::MIN_SUBCHUNK_INDEX; $y <= Chunk::MAX_SUBCHUNK_INDEX; ++$y){
			if(($data = $records[ChunkDataKey::SUBCHUNK . chr(($y + $subChunkKeyOffset) & 0xff)] ?? null) === null){
				$subChunks[$y] = new SubChunk(Block::EMPTY_STATE_ID, [], $biomeArrays[$y]);
				continue;
			}

			$binaryStream = new BinaryStream($data);
			if($binaryStream->feof()){
				throw new CorruptedChunkException("Unexpected empty data for subchunk $y");
			}
			$subChunkVersion = $binaryStream->getByte();
			if($subChunkVersion < self::CURRENT_LEVEL_SUBCHUNK_VERSION){
				$hasBeenUpgraded = true;
			}

			$subChunks[$y] = $this->deserializeSubChunkData(
				$binaryStream,
				$chunkVersion,
				$subChunkVersion,
				$convertedLegacyExtraData[$y] ?? null,
				$biomeArrays[$y],
				new \quark\log\SourceLogger($logger, "Subchunk y=$y v$subChunkVersion")
			);
		}

		return $subChunks;
	}

	/**
	 * Deserializes any available biome data into an array of paletted biomes. Old 2D biomes are extrapolated to 3D.
	 *
	 * @param string[] $records
	 * @phpstan-param array<array-key, string> $records
	 *
	 * @return PalettedBlockArray[]
	 * @phpstan-return array<int, PalettedBlockArray>
	 */
	private function deserializeBiomeData(array $records, int $chunkVersion, \Logger $logger) : array{
		$biomeArrays = [];
		if(($maps2d = $records[ChunkDataKey::HEIGHTMAP_AND_2D_BIOMES] ?? null) !== null){
			$binaryStream = new BinaryStream($maps2d);

			try{
				$binaryStream->get(512); //heightmap, discard it
				$biomes3d = ChunkUtils::extrapolate3DBiomes($binaryStream->get(256)); //never throws
				if(!$binaryStream->feof()){
					$logger->error("Unexpected trailing data after 2D biome data");
				}
			}catch(BinaryDataException $e){
				throw new CorruptedChunkException($e->getMessage(), 0, $e);
			}
			for($i = Chunk::MIN_SUBCHUNK_INDEX; $i <= Chunk::MAX_SUBCHUNK_INDEX; ++$i){
				$biomeArrays[$i] = clone $biomes3d;
			}
		}elseif(($maps3d = $records[ChunkDataKey::HEIGHTMAP_AND_3D_BIOMES] ?? null) !== null){
			$binaryStream = new BinaryStream($maps3d);

			try{
				$binaryStream->get(512);
				$biomeArrays = self::deserialize3dBiomes($binaryStream, $chunkVersion, $logger);
			}catch(BinaryDataException $e){
				throw new CorruptedChunkException($e->getMessage(), 0, $e);
			}
		}else{
			$logger->error("Missing biome data, using default ocean biome");
			for($i = Chunk::MIN_SUBCHUNK_INDEX; $i <= Chunk::MAX_SUBCHUNK_INDEX; ++$i){
				$biomeArrays[$i] = new PalettedBlockArray(BiomeIds::OCEAN); //polyfill
			}
		}

		return $biomeArrays;
	}

	/**
	 * @throws CorruptedChunkException
	 */
	public function loadChunk(int $chunkX, int $chunkZ) : ?LoadedChunkData{
		$records = $this->readChunkRecords($chunkX, $chunkZ);
		$chunkVersion = self::readVersion($records);
		if($chunkVersion === null){
			//TODO: this might be a slightly-corrupted chunk with a missing version field
			return null;
		}

		//TODO: read PM_DATA_VERSION - we'll need it to fix up old chunks

		$logger = new \quark\log\SourceLogger($this->logger, "Loading chunk x=$chunkX z=$chunkZ v$chunkVersion");

		$hasBeenUpgraded = $chunkVersion < self::CURRENT_LEVEL_CHUNK_VERSION;

		switch($chunkVersion){
			case ChunkVersion::v1_21_120: //TODO: ???
			case ChunkVersion::v1_21_40:
				//TODO: BiomeStates became shorts instead of bytes
			case ChunkVersion::v1_18_30:
			case ChunkVersion::v1_18_0_25_beta:
			case ChunkVersion::v1_18_0_24_unused:
			case ChunkVersion::v1_18_0_24_beta:
			case ChunkVersion::v1_18_0_22_unused:
			case ChunkVersion::v1_18_0_22_beta:
			case ChunkVersion::v1_18_0_20_unused:
			case ChunkVersion::v1_18_0_20_beta:
			case ChunkVersion::v1_17_40_unused:
			case ChunkVersion::v1_17_40_20_beta_experimental_caves_cliffs:
			case ChunkVersion::v1_17_30_25_unused:
			case ChunkVersion::v1_17_30_25_beta_experimental_caves_cliffs:
			case ChunkVersion::v1_17_30_23_unused:
			case ChunkVersion::v1_17_30_23_beta_experimental_caves_cliffs:
			case ChunkVersion::v1_16_230_50_unused:
			case ChunkVersion::v1_16_230_50_beta_experimental_caves_cliffs:
			case ChunkVersion::v1_16_220_50_unused:
			case ChunkVersion::v1_16_220_50_beta_experimental_caves_cliffs:
			case ChunkVersion::v1_16_210:
			case ChunkVersion::v1_16_100_57_beta:
			case ChunkVersion::v1_16_100_52_beta:
			case ChunkVersion::v1_16_0:
			case ChunkVersion::v1_16_0_51_beta:
				//TODO: check walls
			case ChunkVersion::v1_12_0_unused2:
			case ChunkVersion::v1_12_0_unused1:
			case ChunkVersion::v1_12_0_4_beta:
			case ChunkVersion::v1_11_1:
			case ChunkVersion::v1_11_0_4_beta:
			case ChunkVersion::v1_11_0_3_beta:
			case ChunkVersion::v1_11_0_1_beta:
			case ChunkVersion::v1_9_0:
			case ChunkVersion::v1_8_0:
			case ChunkVersion::v1_2_13:
			case ChunkVersion::v1_2_0:
			case ChunkVersion::v1_2_0_2_beta:
			case ChunkVersion::v1_1_0_converted_from_console:
			case ChunkVersion::v1_1_0:
				//TODO: check beds
			case ChunkVersion::v1_0_0:
				$convertedLegacyExtraData = $this->deserializeLegacyExtraData($records[ChunkDataKey::LEGACY_BLOCK_EXTRA_DATA] ?? "", $chunkVersion, $logger);
				$biomeArrays = $this->deserializeBiomeData($records, $chunkVersion, $logger);
				$subChunks = $this->deserializeAllSubChunkData($records, $chunkVersion, $hasBeenUpgraded, $convertedLegacyExtraData, $biomeArrays, $logger);
				break;
			case ChunkVersion::v0_9_5:
			case ChunkVersion::v0_9_2:
			case ChunkVersion::v0_9_0:
				$subChunks = $this->deserializeLegacyTerrainData($records, $chunkVersion, $logger);
				break;
			default:
				throw new CorruptedChunkException("don't know how to decode chunk format version $chunkVersion");
		}

		$entities = [];
		if(($entityData = $records[ChunkDataKey::ENTITIES] ?? "") !== ""){
			try{
				$entities = array_map(fn(TreeRoot $root) => $root->mustGetCompoundTag(), NativeNbt::readMultiple($entityData, NativeNbt::BEDROCK));
			}catch(NbtException|NbtDataException $e){
				throw new CorruptedChunkException($e->getMessage(), 0, $e);
			}
		}

		$tiles = [];
		if(($tileData = $records[ChunkDataKey::BLOCK_ENTITIES] ?? "") !== ""){
			try{
				$tiles = array_map(fn(TreeRoot $root) => $root->mustGetCompoundTag(), NativeNbt::readMultiple($tileData, NativeNbt::BEDROCK));
			}catch(NbtException|NbtDataException $e){
				throw new CorruptedChunkException($e->getMessage(), 0, $e);
			}
		}

		$finalisationChr = $records[ChunkDataKey::FINALIZATION] ?? null;
		if($finalisationChr !== null){
			$finalisation = ord($finalisationChr);
			$terrainPopulated = $finalisation === self::FINALISATION_DONE;
		}else{ //older versions didn't have this tag
			$terrainPopulated = true;
		}

		//TODO: tile ticks, biome states (?)

		return new LoadedChunkData(
			data: new ChunkData($subChunks, $terrainPopulated, $entities, $tiles),
			upgraded: $hasBeenUpgraded,
			fixerFlags: LoadedChunkData::FIXER_FLAG_ALL //TODO: fill this by version rather than just setting all flags
		);
	}

	public function saveChunk(int $chunkX, int $chunkZ, ChunkData $chunkData, int $dirtyFlags) : void{
		$records = [
			ChunkDataKey::NEW_VERSION => chr(self::CURRENT_LEVEL_CHUNK_VERSION),
			ChunkDataKey::OLD_VERSION => null,
		];

		$subChunks = $chunkData->getSubChunks();

		if(($dirtyFlags & Chunk::DIRTY_FLAG_BLOCKS) !== 0){

			foreach($subChunks as $y => $subChunk){
				$key = ChunkDataKey::SUBCHUNK . chr($y & 0xff);
				if($subChunk->isEmptyAuthoritative()){
					$records[$key] = null;
				}else{
					$subStream = new BinaryStream();
					$subStream->putByte(self::CURRENT_LEVEL_SUBCHUNK_VERSION);

					$layers = $subChunk->getBlockLayers();
					$subStream->putByte(count($layers));
					foreach($layers as $blocks){
						$this->serializeBlockPalette($subStream, $blocks);
					}

					$records[$key] = $subStream->getBuffer();
				}
			}
		}

		if(($dirtyFlags & Chunk::DIRTY_FLAG_BIOMES) !== 0){
			$records[ChunkDataKey::HEIGHTMAP_AND_2D_BIOMES] = null;
			$stream = new BinaryStream();
			$stream->put(str_repeat("\x00", 512)); //fake heightmap
			self::serialize3dBiomes($stream, $subChunks);
			$records[ChunkDataKey::HEIGHTMAP_AND_3D_BIOMES] = $stream->getBuffer();
		}

		//TODO: use this properly
		$records[ChunkDataKey::FINALIZATION] = chr($chunkData->isPopulated() ? self::FINALISATION_DONE : self::FINALISATION_NEEDS_POPULATION);

		$records[ChunkDataKey::BLOCK_ENTITIES] = self::writeTags($chunkData->getTileNBT());
		$records[ChunkDataKey::ENTITIES] = self::writeTags($chunkData->getEntityNBT());

		$records[ChunkDataKey::HEIGHTMAP_AND_2D_BIOME_COLORS] = null;
		$records[ChunkDataKey::LEGACY_TERRAIN] = null;

		$this->writeChunkRecords($chunkX, $chunkZ, $records);
	}

	/**
	 * @param CompoundTag[] $targets
	 */
	private static function writeTags(array $targets) : ?string{
		return count($targets) > 0 ? NativeNbt::writeMultiple(array_map(fn(CompoundTag $tag) => new TreeRoot($tag), $targets), NativeNbt::BEDROCK) : null;
	}
}
