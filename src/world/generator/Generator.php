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

/**
 * Noise classes used in world generation
 */
namespace quark\world\generator;

use quark\utils\Random;
use quark\utils\Utils;
use quark\world\ChunkManager;
use function preg_match;

abstract class Generator{

	/**
	 * Converts a string world seed into an integer for use by the generator.
	 */
	public static function convertSeed(string $seed) : ?int{
		if($seed === ""){ //empty seed should cause a random seed to be selected - can't use 0 here because 0 is a valid seed
			$convertedSeed = null;
		}elseif(preg_match('/^-?\d+$/', $seed) === 1){ //this avoids treating seeds like "404.4" as integer seeds
			$convertedSeed = (int) $seed;
		}else{
			$convertedSeed = Utils::javaStringHash($seed);
		}

		return $convertedSeed;
	}

	protected Random $random;

	public function __construct(
		protected int $seed,
		protected string $preset
	){
		$this->random = new Random($seed);
	}

	abstract public function generateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void;

	abstract public function populateChunk(ChunkManager $world, int $chunkX, int $chunkZ) : void;
}
