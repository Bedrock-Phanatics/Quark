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
use quark\block\Block;
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

	/**
	 * @var ChunkMetadata[]
	 * @phpstan-var array<int, ChunkMetadata>
	 */
	private array $chunks = [];
	/**
	 * Stored records of loaded chunks that couldn't be decoded, kept so saves write them back unchanged.
	 * @var string[]
	 * @phpstan-var array<int, string>
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
		$this->values->setValue($key->name, $key->flags, ($key->codec->encode)($value));
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
		return count($values) === 0 ? null : new BlockMetadata($values);
	}

	/**
	 * Replaces the block-bound values of the block at the position with $metadata. Put them after the block is placed
	 * there, since placing it would invalidate them.
	 */
	public function putBlock(int $x, int $y, int $z, BlockMetadata $metadata) : void{
		$this->blockChunk($x, $y, $z, true)->putBlockValues(ChunkMetadata::blockHash($x, $y, $z), $metadata->getValues());
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
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
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
	 * they next load. The removal itself is stored before this returns.
	 */
	public function removeNamespace(string $namespace) : void{
		$this->checkUsable();
		if(preg_match('/^[a-z0-9_.-]{1,' . MetadataKey::MAX_NAMESPACE_LENGTH . '}$/D', $namespace) !== 1){
			throw new \InvalidArgumentException("Invalid metadata namespace \"$namespace\"");
		}
		$epoch = $this->epoch + 1;
		$purges = $this->purges;
		$purges[$namespace] = $epoch;
		$values = clone $this->values;
		$values->removeNamespace($namespace);
		$this->provider->saveWorldMetadata(self::encodeRecord($epoch, $purges, $values));
		//chunks saved from here on carry the new epoch, so the removal has to be durable before any of them is
		$this->provider->flush();

		$this->epoch = $epoch;
		$this->purges = $purges;
		$this->values = $values;
		$values->markSaved($values->getChanges());
		foreach($this->chunks as $chunk){
			$chunk->removeNamespace($namespace);
			$chunk->epoch = $epoch;
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
		//only chunks of a loaded, usable world are ever in the map, so a hit needs no other check
		if($y >= $this->minY && $y < $this->maxY && ($chunk = $this->chunks[World::chunkHash($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE)] ?? null) !== null){
			return $chunk;
		}
		if(!$this->world->isInWorld($x, $y, $z)){
			throw new \InvalidArgumentException("Pos x=$x,y=$y,z=$z is outside of the world bounds");
		}
		return $this->chunk($x >> Chunk::COORD_BIT_SIZE, $z >> Chunk::COORD_BIT_SIZE, $create);
	}

	/**
	 * @phpstan-return ($create is true ? ChunkMetadata : ChunkMetadata|null)
	 */
	private function chunk(int $chunkX, int $chunkZ, bool $create) : ?ChunkMetadata{
		$this->checkUsable();
		$chunkHash = World::chunkHash($chunkX, $chunkZ);
		if(!isset($this->chunks[$chunkHash]) && !$this->world->isChunkLoaded($chunkX, $chunkZ) && $this->world->loadChunk($chunkX, $chunkZ) === null){
			if($create){
				throw new WorldException("Cannot set metadata in un-generated terrain");
			}
			return null;
		}
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
			$this->unreadable[$chunkHash] = $record;
			return;
		}
		try{
			$chunk = ChunkMetadata::decode($record);
		}catch(MetadataException $e){
			World::getXZ($chunkHash, $chunkX, $chunkZ);
			$this->world->getLogger()->critical("Metadata of chunk $chunkX $chunkZ can't be read and is kept as stored: " . $e->getMessage());
			$this->unreadable[$chunkHash] = $record;
			return;
		}
		if($chunk->epoch < $this->epoch){
			foreach(Utils::stringifyKeys($this->purges) as $namespace => $epoch){
				if($epoch > $chunk->epoch){
					$chunk->removeNamespace($namespace);
				}
			}
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
		$chunk = $this->chunks[$chunkHash] ?? null;
		if($chunk === null){
			return;
		}
		foreach($chunk->getBlockHashes() as $blockHash){
			$x = $blockHash & Chunk::COORD_MASK;
			$y = $blockHash >> 8;
			$z = ($blockHash >> Chunk::COORD_BIT_SIZE) & Chunk::COORD_MASK;
			if($y >= $this->minY && $y < $this->maxY){
				$this->onBlockChanged($chunkHash, $x, $y, $z, $old->getBlockStateId($x, $y, $z), $new->getBlockStateId($x, $y, $z));
			}
		}
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
		if(isset($this->unreadable[$chunkHash])){
			return $this->unreadable[$chunkHash];
		}
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
			$purges = [];
			for($i = 0, $count = $stream->getUnsignedVarInt(); $i < $count; ++$i){
				$namespace = $stream->get($stream->getByte());
				$purgeEpoch = $stream->getUnsignedVarInt();
				if(isset($purges[$namespace]) || $purgeEpoch > $epoch){
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
