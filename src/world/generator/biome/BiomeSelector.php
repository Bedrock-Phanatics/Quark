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

namespace quark\world\generator\biome;

use quark\utils\Random;
use quark\world\biome\Biome;
use quark\world\biome\BiomeRegistry;
use quark\world\biome\UnknownBiome;
use quark\world\generator\noise\Simplex;

abstract class BiomeSelector{
	private Simplex $temperature;
	private Simplex $rainfall;

	/**
	 * @var Biome[]|\SplFixedArray
	 * @phpstan-var \SplFixedArray<Biome>
	 */
	private \SplFixedArray $map;

	public function __construct(Random $random){
		$this->temperature = new Simplex($random, 2, 1 / 16, 1 / 512);
		$this->rainfall = new Simplex($random, 2, 1 / 16, 1 / 512);
	}

	/**
	 * Lookup function called by recalculate() to determine the biome to use for this temperature and rainfall.
	 *
	 * @return int biome ID 0-255
	 */
	abstract protected function lookup(float $temperature, float $rainfall) : int;

	public function recalculate() : void{
		$this->map = new \SplFixedArray(64 * 64);

		$biomeRegistry = BiomeRegistry::getInstance();
		for($i = 0; $i < 64; ++$i){
			for($j = 0; $j < 64; ++$j){
				$biome = $biomeRegistry->getBiome($this->lookup($i / 63, $j / 63));
				if($biome instanceof UnknownBiome){
					throw new \RuntimeException("Unknown biome returned by selector with ID " . $biome->getId());
				}
				$this->map[$i + ($j << 6)] = $biome;
			}
		}
	}

	public function getTemperature(float $x, float $z) : float{
		return ($this->temperature->noise2D($x, $z, true) + 1) / 2;
	}

	public function getRainfall(float $x, float $z) : float{
		return ($this->rainfall->noise2D($x, $z, true) + 1) / 2;
	}

	public function pickBiome(float $x, float $z) : Biome{
		$temperature = (int) ($this->getTemperature($x, $z) * 63);
		$rainfall = (int) ($this->getRainfall($x, $z) * 63);

		return $this->map[$temperature + ($rainfall << 6)];
	}
}
