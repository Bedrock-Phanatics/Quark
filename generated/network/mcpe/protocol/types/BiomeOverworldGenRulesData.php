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
final class BiomeOverworldGenRulesData{
	/**
	 * @param list<BiomeWeightedData> $hillsTransformations
	 * @param list<BiomeWeightedData> $mutateTransformations
	 * @param list<BiomeWeightedData> $riverTransformations
	 * @param list<BiomeWeightedData> $shoreTransformations
	 * @param list<BiomeConditionalTransformation> $preHillsEdge
	 * @param list<BiomeConditionalTransformation> $postShoreEdge
	 * @param list<BiomeTemperatureWeight> $climate
	 */
	public function __construct(
		public array $hillsTransformations,
		public array $mutateTransformations,
		public array $riverTransformations,
		public array $shoreTransformations,
		public array $preHillsEdge,
		public array $postShoreEdge,
		public array $climate,
	){}
}
