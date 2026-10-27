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
final class BiomeDefinitionChunkGenData{
	/**
	 * @param list<BiomeConsolidatedFeature>|null $consolidatedFeatures
	 * @param list<BiomeElementData>|null $surfaceMaterialAdjustments
	 * @param list<BiomeConditionalTransformation>|null $legacyWorldGenRules
	 * @param list<BiomeReplacementData>|null $replacementBiomes
	 */
	public function __construct(
		public ?BiomeClimate $climate = null,
		public ?array $consolidatedFeatures = null,
		public ?BiomeMountainParameters $mountainParams = null,
		public ?array $surfaceMaterialAdjustments = null,
		public ?BiomeOverworldGenRulesData $overworldGenRules = null,
		public ?BiomeMultiNoiseRules $multinoiseGenRules = null,
		public ?array $legacyWorldGenRules = null,
		public ?array $replacementBiomes = null,
		public ?int $villageType = null,
		public ?BiomeSurfaceBuilder $surfaceBuilderData = null,
		public ?BiomeSurfaceBuilder $subsurfaceBuilderData = null,
	){}
}
