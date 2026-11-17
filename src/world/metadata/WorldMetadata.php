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

namespace quark\world\metadata;

use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\Limits;
use quark\block\Block;
use quark\math\Vector3;
use quark\utils\Utils;
use quark\world\format\Chunk;
use quark\world\format\io\MetadataWorldProvider;
use quark\world\World;
use quark\world\WorldException;
use function count;
use function crc32;
use function pack;
use function preg_match;
use function strlen;
use function substr;
use function unpack;

/**
 * Persistent plugin data attached to blocks, chunks or the whole world, from {@see World::getMetadata()}.
 *
 * Block and chunk values are stored in the chunk's own record and written in the same atomic write as its terrain, so
 * after a crash both come back from the same save. Values are written when their chunk is saved, like blocks: a
 * value set in a world with autosave off is only kept by a forced save. World values are written by world saves.
 *
 * Reading or writing a value in an unloaded chunk loads it synchronously. A chunk whose stored metadata can't be read
 * refuses access with a {@see MetadataException} and keeps the stored bytes untouched.
 */
final class WorldMetadata{
	private const RECORD_VERSION = 1;
	private const MAX_PURGES = 8192;

	/**
	 * @var ChunkMetadata[]
	 * @phpstan-var array<int, ChunkMetadata>
	 */
	private array $chunks = [];
	/**
	 * Loaded chunks whose stored metadata must be left untouched.
	 * @var true[]
	 * @phpstan-var array<int, true>
	 */
	private array $unreadable = [];

	private ChunkMetadata $values;
	/**
	 * Namespace => epoch of its last removal. Chunks stored with an older epoch still hold its values and lose them
	 * when they load, so a removal doesn't have to rewrite the whole world.
	 * @var int[]
	 * @phpstan-var array<string, int>
	 */
	private array $purges = [];
	private int $epoch = 0;
	private bool $purgeNeedsFlush = false;
	/** Set when the world record can't be read; every operation fails rather than risk overwriting it. */
	private ?string $recordError = null;
	private int $minY;
	private int $maxY;

	/**
	 * @internal Use {@see World::getMetadata()}.
	 */
	public function __construct(
		private World $world,
		private MetadataWorldProvider $provider,
		?string $record
	){
		$this->minY = $world->getMinY();
		$this->maxY = $world->getMaxY();
		$this->values = new ChunkMetadata();
		if($record !== null){
			try{
				$this->decodeRecord($record);
			}catch(MetadataException $e){
				$this->recordError = $e->getMessage();
				$this->values = new ChunkMetadata();
				$world->getLogger()->critical("World metadata can't be read, so metadata is unavailable in this world until it is repaired: " . $e->getMessage());
			}
		}
	}

	/**
	 * @phpstan-template T
	 * @phpstan-param MetadataKey<T> $key
	 * @phpstan-return T|null
	 */
	public function getBlock(int $x, int $y, int $z, MetadataKey $key) : mixed{
		$value = $this->blockChunk($x, $y, $z, false)?->getBlockValue(ChunkMetadata::blockHash($x, $y, $z), $key->name);
		if($value === null){
			return null;
		}
		try{
			return ($key->codec->decode)($value);
		}catch(\Throwable $e){
			throw new MetadataException("Metadata $key->name at x=$x y=$y z=$z can't be decoded: " . $e->getMessage(), 0, $e);
		}
	}

	/**
	 * @phpstan-template T
	 * @phpstan-param MetadataKey<T> $key
	 * @phpstan-param T $value
	 */
	public function setBlock(int $x, int $y, int $z, MetadataKey $key, mixed $value) : void{
		$encoded = ($key->codec->encode)($value);
		$this->blockChunk($x, $y, $z, true)->setBlockValue(ChunkMetadata::blockHash($x, $y, $z), $key->name, $key->flags, $encoded);
	}

	/** @phpstan-param MetadataKey<covariant mixed> $key */
	public function removeBlock(int $x, int $y, int $z, MetadataKey $key) : bool{
		return $this->blockChunk($x, $y, $z, false)?->removeBlockValue(ChunkMetadata::blockHash($x, $y, $z), $key->name) ?? false;
	}

	/** @phpstan-param MetadataKey<covariant mixed> $key */
	public function hasBlock(int $x, int $y, int $z, MetadataKey $key) : bool{
		return $this->blockChunk($x, $y, $z, false)?->getBlockValue(ChunkMetadata::blockHash($x, $y, $z), $key->name) !== null;
	}

	/**
	 * @phpstan-template T
	 * @phpstan-param MetadataKey<T> $key
	 * @phpstan-return T|null
	 */
	public function getChunk(int $chunkX, int $chunkZ, MetadataKey $key) : mixed{
		$value = $this->chunk($chunkX, $chunkZ, false)?->getValue($key->name);
		return $value === null ? null : self::decode($key, $value, "chunk $chunkX $chunkZ");
	}

	/**
	 * @phpstan-template T
	 * @phpstan-param MetadataKey<T> $key
	 * @phpstan-param T $value
	 */
	public function setChunk(int $chunkX, int $chunkZ, MetadataKey $key, mixed $value) : void{
		$encoded = ($key->codec->encode)($value);
		$this->chunk($chunkX, $chunkZ, true)->setValue($key->name, $key->flags, $encoded);
	}

	/** @phpstan-param MetadataKey<covariant mixed> $key */
	public function removeChunk(int $chunkX, int $chunkZ, MetadataKey $key) : bool{
		return $this->chunk($chunkX, $chunkZ, false)?->removeValue($key->name) ?? false;
	}

	/** @phpstan-param MetadataKey<covariant mixed> $key */
	public function hasChunk(int $chunkX, int $chunkZ, MetadataKey $key) : bool{
		return $this->chunk($chunkX, $chunkZ, false)?->getValue($key->name) !== null;
	}

	/**
	 * @phpstan-template T
	 * @phpstan-param MetadataKey<T> $key
	 * @phpstan-return T|null
	 */
	public function get(MetadataKey $key) : mixed{
		$this->checkUsable();
		$value = $this->values->getValue($key->name);
		return $value === null ? null : self::decode($key, $value, "world " . $this->world->getFolderName());
	}

	/**
	 * @phpstan-template T
	 * @phpstan-param MetadataKey<T> $key
	 * @phpstan-param T $value
	 */
	public function set(MetadataKey $key, mixed $value) : void{
		$this->checkUsable();
		$encoded = ($key->codec->encode)($value);
		$this->checkUsable();
		$this->values->setValue($key->name, $key->flags, $encoded);
	}

	/** @phpstan-param MetadataKey<covariant mixed> $key */
	public function remove(MetadataKey $key) : bool{
		$this->checkUsable();
		return $this->values->removeValue($key->name);
	}

	/** @phpstan-param MetadataKey<covariant mixed> $key */
	public function has(MetadataKey $key) : bool{
		$this->checkUsable();
		return $this->values->getValue($key->name) !== null;
	}

	/**
	 * Returns a copy of the block's block-bound values, without those of keys created with $copyable = false.
	 * Position-bound values never leave their position.
	 */
	public function copyBlock(int $x, int $y, int $z) : ?BlockMetadata{
		$values = $this->blockChunk($x, $y, $z, false)?->takeBlockValues(ChunkMetadata::blockHash($x, $y, $z), true) ?? [];
		return count($values) === 0 ? null : new BlockMetadata($values);
	}

	/**
	 * Removes and returns the block's block-bound values, for moving them with the block. Take them before the block
	 * is removed from its old position, since that removal would invalidate them.
	 */
	public function takeBlock(int $x, int $y, int $z) : ?BlockMetadata{
		$values = $this->blockChunk($x, $y, $z, false)?->takeBlockValues(ChunkMetadata::blockHash($x, $y, $z), false) ?? [];
		return count($values) === 0 ? null : new BlockMetadata($values, singleUse: true);
	}

	/**
	 * Replaces the block-bound values of the block at the position with $metadata. Put them after the block is placed
	 * there, since placing it would invalidate them.
	 */
	public function putBlock(int $x, int $y, int $z, BlockMetadata $metadata) : void{
		$chunk = $this->blockChunk($x, $y, $z, true);
		// Chunk loading may call plugins which also hold this snapshot.
		$chunk->putBlockValues(ChunkMetadata::blockHash($x, $y, $z), $metadata->getValues());
		$metadata->markPlaced();
	}

	/**
	 * Checks a piston move in movement order, accounting for space freed by earlier moves.
	 *
	 * @internal
	 * @param iterable<array{Vector3, Vector3}> $moves
	 */
	public function canMoveBlocks(iterable $moves) : bool{
		if($this->chunks === [] && $this->unreadable === [] && $this->recordError === null){
			return true;
		}
		$projected = [];
		try{
			foreach($moves as [$source, $destination]){
				$from = $this->projectChunk($projected, $source);
				$to = $this->projectChunk($projected, $destination);
				$values = $from->takeBlockValues(ChunkMetadata::blockHash($source->getFloorX(), $source->getFloorY(), $source->getFloorZ()), false);
				$target = ChunkMetadata::blockHash($destination->getFloorX(), $destination->getFloorY(), $destination->getFloorZ());
				$to->putBlockValues($target, $values);
			}
		}catch(MetadataException|\LengthException){
			return false;
		}
		return true;
	}

	/** @phpstan-param array<int, ChunkMetadata> $projected */
	private function projectChunk(array &$projected, Vector3 $pos) : ChunkMetadata{
		$x = $pos->getFloorX();
		$z = $pos->getFloorZ();
		$hash = World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE);
		if(!isset($projected[$hash])){
			$chunk = $this->blockChunk($x, $pos->getFloorY(), $z, false);
			$projected[$hash] = $chunk !== null ? clone $chunk : new ChunkMetadata($this->epoch);
		}
		return $projected[$hash];
	}

	/**
	 * Returns the positions in the chunk that hold block values, without scanning its blocks.
	 *
	 * @return int[][]
	 * @phpstan-return list<array{int, int, int}>
	 */
	public function getBlocks(int $chunkX, int $chunkZ) : array{
		$positions = [];
		foreach($this->chunk($chunkX, $chunkZ, false)?->getBlockHashes() ?? [] as $blockHash){
			$positions[] = [($chunkX << Chunk::COORD_BIT_SIZE) | ($blockHash & Chunk::COORD_MASK), $blockHash >> 8, ($chunkZ << Chunk::COORD_BIT_SIZE) | (($blockHash >> Chunk::COORD_BIT_SIZE) & Chunk::COORD_MASK)];
		}
		return $positions;
	}

	/**
	 * Removes every value of the chunk and its blocks, including stored data that couldn't be read.
	 */
	public function clearChunk(int $chunkX, int $chunkZ) : void{
		$this->checkUsable();
		$chunkHash = self::checkedChunkHash($chunkX, $chunkZ);
		if(!$this->world->isChunkLoaded($chunkX, $chunkZ) && $this->world->loadChunk($chunkX, $chunkZ) === null){
			return;
		}
		if(isset($this->chunks[$chunkHash]) || isset($this->unreadable[$chunkHash])){
			unset($this->unreadable[$chunkHash]);
			$this->chunks[$chunkHash] = $cleared = new ChunkMetadata($this->epoch);
			$cleared->markDirty();
		}
	}

	/**
	 * Removes every value stored under the namespace in the whole world. Loaded chunks lose them now, the rest when
	 * they next load. The removal itself is stored before this returns. If flushing fails after the write, the
	 * removal stays applied in memory and chunk metadata writes retry the flush before using the new epoch.
	 */
	public function removeNamespace(string $namespace) : void{
		$this->checkUsable();
		if(preg_match('/^[a-z0-9_.-]{1,' . MetadataKey::MAX_NAMESPACE_LENGTH . '}$/D', $namespace) !== 1){
			throw new \InvalidArgumentException("Invalid metadata namespace \"$namespace\"");
		}
		if($this->epoch === Limits::UINT32_MAX || (!isset($this->purges[$namespace]) && count($this->purges) >= self::MAX_PURGES)){
			throw new \LengthException("World metadata purge limit reached");
		}
		$epoch = $this->epoch + 1;
		$purges = $this->purges;
		$purges[$namespace] = $epoch;
		$values = clone $this->values;
		$pending = [$namespace => $epoch];
		$values->removePurgedNamespaces($pending);
		$this->provider->saveWorldMetadata(self::encodeRecord($epoch, $purges, $values));

		$this->epoch = $epoch;
		$this->purges = $purges;
		$this->values = $values;
		$values->markSaved($values->getChanges());
		foreach($this->chunks as $chunk){
			$chunk->removePurgedNamespaces($pending);
			$chunk->epoch = $epoch;
		}
		$this->purgeNeedsFlush = true;
		$this->flushPurge();
	}

	private function flushPurge() : void{
		if($this->purgeNeedsFlush){
			$this->provider->flush();
			$this->purgeNeedsFlush = false;
		}
	}

	/**
	 * @phpstan-template T
	 * @phpstan-param MetadataKey<T> $key
	 * @phpstan-return T
	 * @throws MetadataException
	 */
	public static function decode(MetadataKey $key, string $value, string $where) : mixed{
		try{
			return ($key->codec->decode)($value);
		}catch(\Throwable $e){
			throw new MetadataException("Metadata $key->name at $where can't be decoded: " . $e->getMessage(), 0, $e);
		}
	}

	private function checkUsable() : void{
		if(!$this->world->isLoaded()){
			throw new WorldException("World " . $this->world->getFolderName() . " is unloaded");
		}
		if($this->recordError !== null){
			throw new MetadataException("World metadata of " . $this->world->getFolderName() . " can't be read: $this->recordError");
		}
	}

	/**
	 * @phpstan-return ($create is true ? ChunkMetadata : ChunkMetadata|null)
	 */
	private function blockChunk(int $x, int $y, int $z, bool $create) : ?ChunkMetadata{
		if($x < Limits::INT32_MIN || $x > Limits::INT32_MAX || $z < Limits::INT32_MIN || $z > Limits::INT32_MAX || $y < $this->minY || $y >= $this->maxY){
			throw new \InvalidArgumentException("Pos x=$x,y=$y,z=$z is outside of the world bounds");
		}
		// Only chunks of a loaded, usable world are in the map.
		if(($chunk = $this->chunks[World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)] ?? null) !== null){
			return $chunk;
		}
		return $this->chunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE, $create);
	}

	private static function checkedChunkHash(int $chunkX, int $chunkZ) : int{
		if($chunkX < (Limits::INT32_MIN >> Chunk::COORD_BIT_SIZE) || $chunkX > (Limits::INT32_MAX >> Chunk::COORD_BIT_SIZE) ||
			$chunkZ < (Limits::INT32_MIN >> Chunk::COORD_BIT_SIZE) || $chunkZ > (Limits::INT32_MAX >> Chunk::COORD_BIT_SIZE)){
			throw new \InvalidArgumentException("Chunk x=$chunkX z=$chunkZ is outside of the world bounds");
		}
		return World::chunkHash($chunkX, $chunkZ);
	}

	/**
	 * @phpstan-return ($create is true ? ChunkMetadata : ChunkMetadata|null)
	 */
	private function chunk(int $chunkX, int $chunkZ, bool $create) : ?ChunkMetadata{
		$this->checkUsable();
		$chunkHash = self::checkedChunkHash($chunkX, $chunkZ);
		if(!isset($this->chunks[$chunkHash]) && !$this->world->isChunkLoaded($chunkX, $chunkZ) && $this->world->loadChunk($chunkX, $chunkZ) === null){
			if($create){
				throw new WorldException("Cannot set metadata in un-generated terrain");
			}
			return null;
		}
		$this->checkUsable();
		if(isset($this->unreadable[$chunkHash])){
			throw new MetadataException("Metadata of chunk $chunkX $chunkZ can't be read, see the error logged when it loaded");
		}
		if(!$create || isset($this->chunks[$chunkHash])){
			return $this->chunks[$chunkHash] ?? null;
		}
		return $this->chunks[$chunkHash] = new ChunkMetadata($this->epoch);
	}

	/**
	 * @internal
	 */
	public function onChunkLoaded(int $chunkHash, string $record) : void{
		if($this->recordError !== null){
			$this->unreadable[$chunkHash] = true;
			return;
		}
		try{
			$chunk = ChunkMetadata::decode($record);
			if($chunk->epoch > $this->epoch){
				throw new MetadataException("Chunk metadata is newer than the world's purge record");
			}
		}catch(MetadataException $e){
			World::getXZ($chunkHash, $chunkX, $chunkZ);
			$this->world->getLogger()->critical("Metadata of chunk $chunkX $chunkZ can't be read and is kept as stored: " . $e->getMessage());
			$this->unreadable[$chunkHash] = true;
			return;
		}
		if($chunk->epoch < $this->epoch){
			$chunk->removePurgedNamespaces($this->purges);
			$chunk->epoch = $this->epoch;
		}
		$this->chunks[$chunkHash] = $chunk;
	}

	/**
	 * Drops everything held for the world's chunks once it is unloaded, even chunks something kept loaded.
	 *
	 * @internal
	 */
	public function close() : void{
		$this->chunks = [];
		$this->unreadable = [];
		$this->values = new ChunkMetadata();
		$this->purges = [];
	}

	/**
	 * @internal
	 */
	public function onChunkUnloaded(int $chunkHash) : void{
		unset($this->chunks[$chunkHash], $this->unreadable[$chunkHash]);
	}

	/**
	 * @internal
	 */
	public function onBlockChanged(int $chunkHash, int $x, int $y, int $z, int $oldStateId, int $newStateId) : void{
		if($oldStateId !== $newStateId && isset($this->chunks[$chunkHash])){
			$this->chunks[$chunkHash]->onBlockChanged(ChunkMetadata::blockHash($x, $y, $z), ($oldStateId >> Block::INTERNAL_STATE_DATA_BITS) !== ($newStateId >> Block::INTERNAL_STATE_DATA_BITS));
		}
	}

	/**
	 * Applies retention to every block holding values, as if each block of $old had been set to the one in $new.
	 *
	 * @internal
	 */
	public function onChunkReplaced(int $chunkHash, Chunk $old, Chunk $new) : void{
		($this->chunks[$chunkHash] ?? null)?->onChunkReplaced($old, $new, $this->minY, $this->maxY);
	}

	/**
	 * Returns a mark for {@see markChunkSaved()} if the chunk's metadata changed since it was last stored.
	 *
	 * @internal
	 */
	public function getChunkSaveMark(int $chunkHash) : ?int{
		$chunk = $this->chunks[$chunkHash] ?? null;
		return $chunk !== null && $chunk->isDirty() ? $chunk->getChanges() : null;
	}

	/**
	 * The record to store for the chunk, null for none.
	 *
	 * @internal
	 */
	public function encodeChunk(int $chunkHash) : ?string{
		$this->flushPurge();
		$chunk = $this->chunks[$chunkHash] ?? null;
		return $chunk === null || $chunk->isEmpty() ? null : $chunk->encode();
	}

	/**
	 * @internal
	 */
	public function markChunkSaved(int $chunkHash, int $mark) : void{
		$chunk = $this->chunks[$chunkHash] ?? null;
		if($chunk === null){
			return;
		}
		$chunk->markSaved($mark);
		if(!$chunk->isDirty() && $chunk->isEmpty()){
			unset($this->chunks[$chunkHash]);
		}
	}

	/**
	 * Stores the world values if they changed. Never overwrites a world record that couldn't be read.
	 *
	 * @internal
	 */
	public function save() : void{
		$this->flushPurge();
		if($this->recordError !== null || !$this->values->isDirty()){
			return;
		}
		$mark = $this->values->getChanges();
		$this->provider->saveWorldMetadata($this->values->isEmpty() && $this->epoch === 0 ? null : self::encodeRecord($this->epoch, $this->purges, $this->values));
		$this->values->markSaved($mark);
	}

	/**
	 * World record: version, purge epoch, purges (namespace, epoch), the world values as a chunk record, then a CRC32.
	 *
	 * @param int[] $purges
	 * @phpstan-param array<string, int> $purges
	 */
	private static function encodeRecord(int $epoch, array $purges, ChunkMetadata $values) : string{
		$stream = new BinaryStream();
		$stream->putByte(self::RECORD_VERSION);
		$stream->putUnsignedVarInt($epoch);
		$stream->putUnsignedVarInt(count($purges));
		foreach(Utils::stringifyKeys($purges) as $namespace => $purgeEpoch){
			$stream->putByte(strlen($namespace));
			$stream->put($namespace);
			$stream->putUnsignedVarInt($purgeEpoch);
		}
		$stream->put($values->encode());
		$payload = $stream->getBuffer();
		return $payload . pack("V", crc32($payload));
	}

	/**
	 * @throws MetadataException
	 */
	private function decodeRecord(string $record) : void{
		if(strlen($record) < 5){
			throw new MetadataException("World metadata record is truncated");
		}
		if(strlen($record) > ChunkMetadata::MAX_RECORD_BYTES + self::MAX_PURGES * (MetadataKey::MAX_NAMESPACE_LENGTH + 6) + 15){
			throw new MetadataException("World metadata record exceeds the size limit");
		}
		$payload = substr($record, 0, -4);
		/** @var int[] $crc */
		$crc = unpack("V", substr($record, -4));
		if($crc[1] !== crc32($payload)){
			throw new MetadataException("World metadata record checksum mismatch");
		}
		$stream = new BinaryStream($payload);
		try{
			$version = $stream->getByte();
			if($version !== self::RECORD_VERSION){
				throw new MetadataException("Unsupported world metadata record version $version");
			}
			$epoch = $stream->getUnsignedVarInt();
			if($epoch > Limits::UINT32_MAX){
				throw new MetadataException("Invalid world metadata purge epoch");
			}
			$purges = [];
			$count = $stream->getUnsignedVarInt();
			if($count > self::MAX_PURGES){
				throw new MetadataException("Too many world metadata purges");
			}
			for($i = 0; $i < $count; ++$i){
				$namespace = $stream->get($stream->getByte());
				$purgeEpoch = $stream->getUnsignedVarInt();
				if(preg_match('/^[a-z0-9_.-]{1,' . MetadataKey::MAX_NAMESPACE_LENGTH . '}$/D', $namespace) !== 1 || isset($purges[$namespace]) || $purgeEpoch === 0 || $purgeEpoch > $epoch){
					throw new MetadataException("Invalid world metadata purge of \"$namespace\"");
				}
				$purges[$namespace] = $purgeEpoch;
			}
			$values = ChunkMetadata::decode($stream->getRemaining());
		}catch(BinaryDataException $e){
			throw new MetadataException("World metadata record is truncated: " . $e->getMessage(), 0, $e);
		}
		$this->epoch = $epoch;
		$this->purges = $purges;
		$this->values = $values;
	}
}
