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

namespace quark\world\format\io;

/**
 * A writable format that persists world metadata. Worlds stored in any other format refuse metadata instead of
 * dropping it.
 *
 * The contract: loadChunk() returns the chunk's metadata record in {@see ChunkData::getMetadata()}, and saveChunk()
 * stores ChunkData's record (deleting it when null) in the same atomic write as the rest of the chunk whenever
 * {@see \quark\world\format\Chunk::DIRTY_FLAG_METADATA} is set, or writes only metadata when passed
 * {@see \quark\world\format\Chunk::DIRTY_FLAG_METADATA_ONLY} alone. Other writes leave the record untouched.
 */
interface MetadataWorldProvider extends WritableWorldProvider{

	/**
	 * Returns the world-wide metadata record, or null if there is none.
	 */
	public function loadWorldMetadata() : ?string;

	/**
	 * Replaces the world-wide metadata record atomically, or deletes it when null.
	 */
	public function saveWorldMetadata(?string $record) : void;
}
