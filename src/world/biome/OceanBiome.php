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

namespace quark\world\biome;

use quark\block\VanillaBlocks;
use quark\world\generator\populator\TallGrass;

class OceanBiome extends Biome{

	public function __construct(){
		$this->setGroundCover([
			VanillaBlocks::GRAVEL(),
			VanillaBlocks::GRAVEL(),
			VanillaBlocks::GRAVEL(),
			VanillaBlocks::GRAVEL(),
			VanillaBlocks::GRAVEL()
		]);

		$tallGrass = new TallGrass();
		$tallGrass->setBaseAmount(5);

		$this->addPopulator($tallGrass);

		$this->setElevation(46, 58);

		$this->temperature = 0.5;
		$this->rainfall = 0.5;
	}

	public function getName() : string{
		return "Ocean";
	}
}
