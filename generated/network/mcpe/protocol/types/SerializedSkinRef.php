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

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class SerializedSkinRef{
	/**
	 * @param list<AnimatedImageData> $animatedImageData
	 * @param list<PersonaPiece> $personaPieces
	 * @param list<SerializedSkinRefPieceTintColorsEntry> $pieceTintColors
	 */
	public function __construct(
		public string $id,
		public string $playFabId,
		public string $resourcePatch,
		public SkinImage $imageData,
		public array $animatedImageData,
		public SkinImage $capeImageData,
		public string $geometryData,
		public string $geometryDataMinEngineVersion,
		public string $animationData,
		public string $capeId,
		public string $fullId,
		public int $armSize,
		public int $skinColor,
		public array $personaPieces,
		public array $pieceTintColors,
		public bool $isPremium,
		public bool $isPersona,
		public bool $isPersonaCapeOnClassicSkin,
		public bool $isPrimaryUser,
		public bool $overridesPlayerAppearance,
		public string $trustedSkinFlag,
		public string $profileHash,
	){}
}
