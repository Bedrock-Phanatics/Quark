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

use quark\world\format\io\ChunkData;
use quark\world\format\io\LoadedChunkData;
use quark\world\format\io\MetadataWorldProvider;
use quark\world\format\io\WorldData;

/**
 * Passes everything to a real provider, failing the calls a test arms before they reach it.
 */
final class FaultyProvider implements MetadataWorldProvider{

	public int $failChunkSaves = 0;
	public int $failWorldSaves = 0;
	public int $failFlushes = 0;
	/** @var list<array{int, int, int}> */
	public array $chunkSaves = [];

	public function __construct(public readonly MetadataWorldProvider $inner){}

	public function saveChunk(int $chunkX, int $chunkZ, ChunkData $chunkData, int $dirtyFlags) : void{
		if($this->failChunkSaves > 0){
			--$this->failChunkSaves;
			throw new \RuntimeException("injected chunk save failure");
		}
		$this->inner->saveChunk($chunkX, $chunkZ, $chunkData, $dirtyFlags);
		$this->chunkSaves[] = [$chunkX, $chunkZ, $dirtyFlags];
	}

	public function saveWorldMetadata(?string $record) : void{
		if($this->failWorldSaves > 0){
			--$this->failWorldSaves;
			throw new \RuntimeException("injected world metadata save failure");
		}
		$this->inner->saveWorldMetadata($record);
	}

	public function flush() : void{
		if($this->failFlushes > 0){
			--$this->failFlushes;
			throw new \RuntimeException("injected flush failure");
		}
		$this->inner->flush();
	}

	public function loadWorldMetadata() : ?string{ return $this->inner->loadWorldMetadata(); }

	public function getWorldMinY() : int{ return $this->inner->getWorldMinY(); }

	public function getWorldMaxY() : int{ return $this->inner->getWorldMaxY(); }

	public function getPath() : string{ return $this->inner->getPath(); }

	public function loadChunk(int $chunkX, int $chunkZ) : ?LoadedChunkData{ return $this->inner->loadChunk($chunkX, $chunkZ); }

	public function doGarbageCollection() : void{ $this->inner->doGarbageCollection(); }

	public function getWorldData() : WorldData{ return $this->inner->getWorldData(); }

	public function close() : void{ $this->inner->close(); }

	public function getAllChunks(bool $skipCorrupted = false, ?\Logger $logger = null) : \Generator{ return $this->inner->getAllChunks($skipCorrupted, $logger); }

	public function calculateChunkCount() : int{ return $this->inner->calculateChunkCount(); }
}
