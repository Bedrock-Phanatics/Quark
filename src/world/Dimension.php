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

namespace quark\world;

use pocketmine\network\mcpe\protocol\types\DimensionIds;
use quark\network\mcpe\serializer\ChunkSerializer;
use quark\world\format\SubChunk;
use function preg_match;

/**
 * An immutable dimension type shared by any number of worlds.
 *
 * Bedrock clients only know the overworld, nether and end, so every dimension is shown to the client as one of those
 * ($networkId) and must fit inside that dimension's client height range.
 */
final class Dimension{
	public const OVERWORLD = "minecraft:overworld";
	public const NETHER = "minecraft:nether";
	public const THE_END = "minecraft:the_end";

	/**
	 * @param int $minY Lowest buildable Y, a multiple of 16
	 * @param int $maxY Exclusive build limit, a multiple of 16
	 * @phpstan-param DimensionIds::* $networkId
	 * @throws \InvalidArgumentException
	 */
	public function __construct(
		public readonly string $identifier,
		public readonly int $networkId,
		public readonly int $minY,
		public readonly int $maxY,
		public readonly bool $skyLight = true,
		public readonly bool $weather = true,
		public readonly bool $bedWorks = true,
		public readonly bool $respawnAnchorWorks = false
	){
		if(preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/', $identifier) !== 1){
			throw new \InvalidArgumentException("Dimension identifier \"$identifier\" must be a lowercase namespaced ID like \"plugin:name\"");
		}
		[$minSubChunk, $maxSubChunk] = ChunkSerializer::getDimensionChunkBounds($networkId);
		$clientMinY = $minSubChunk * SubChunk::EDGE_LENGTH;
		$clientMaxY = ($maxSubChunk + 1) * SubChunk::EDGE_LENGTH;
		if($minY % SubChunk::EDGE_LENGTH !== 0 || $maxY % SubChunk::EDGE_LENGTH !== 0 || $minY >= $maxY || $minY < $clientMinY || $maxY > $clientMaxY){
			throw new \InvalidArgumentException("Dimension \"$identifier\" height $minY..$maxY must be 16-aligned and within the client's $clientMinY..$clientMaxY");
		}
	}
}
