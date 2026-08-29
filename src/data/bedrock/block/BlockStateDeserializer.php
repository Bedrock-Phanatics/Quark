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

namespace quark\data\bedrock\block;

/**
 * Implementors of this interface decide how a block should be deserialized and represented at runtime. This is used by
 * world providers when decoding blockstates into block IDs.
 *
 * @phpstan-type BlockStateId int
 */
interface BlockStateDeserializer{
	/**
	 * Deserializes blockstate NBT into an implementation-defined blockstate ID, for runtime paletted storage.
	 *
	 * @phpstan-return BlockStateId
	 * @throws BlockStateDeserializeException
	 */
	public function deserialize(BlockStateData $stateData) : int;
}
