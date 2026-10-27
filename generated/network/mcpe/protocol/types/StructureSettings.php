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

namespace quark\network\mcpe\protocol\types;

use pocketmine\math\Vector3;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class StructureSettings{
	public function __construct(
		public string $structurePaletteName,
		public bool $shouldIgnoreEntities,
		public bool $shouldIgnoreBlocks,
		public bool $shouldAllowNonTickingPlayerAndTickingAreaChunks,
		public Vector3 $structureSize,
		public Vector3 $structureOffset,
		public int $lastEditPlayer,
		public int $rotation,
		public int $mirror,
		public int $animationMode,
		public float $animationSeconds,
		public float $integrityValue,
		public int $integritySeed,
		public Vector3 $rotationPivot,
	){}
}
