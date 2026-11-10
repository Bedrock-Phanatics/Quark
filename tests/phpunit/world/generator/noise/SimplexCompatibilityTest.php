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

namespace quark\world\generator\noise;

use PHPUnit\Framework\TestCase;
use quark\utils\Random;

final class SimplexCompatibilityTest extends TestCase{
	/**
	 * @return \Generator<string, array{int, int, float, float}>
	 */
	public static function generators() : \Generator{
		//the parameters Normal, Nether and BiomeSelector use
		yield "normal" => [0, 4, 1 / 4, 1 / 32];
		yield "nether" => [1234567, 4, 1 / 4, 1 / 64];
		yield "biomes" => [-987654321, 2, 1 / 16, 1 / 512];
		yield "single octave" => [42, 1, 0.5, 1.0];
	}

	/**
	 * @dataProvider generators
	 */
	public function testNoiseMatchesTheOldImplementation(int $seed, int $octaves, float $persistence, float $expansion) : void{
		$legacyRandom = new Random($seed);
		$random = new Random($seed);
		$legacy = new LegacySimplex($legacyRandom, $octaves, $persistence, $expansion);
		$native = new Simplex($random, $octaves, $persistence, $expansion);
		self::assertSame($legacyRandom->nextInt(), $random->nextInt(), "both consume the generator RNG the same way");

		$points = new Random($seed ^ 0x5f3759df);
		for($i = 0; $i < 2000; ++$i){
			$x = ($points->nextFloat() - 0.5) * 60000;
			$y = $points->nextFloat() * 400 - 64;
			$z = ($points->nextFloat() - 0.5) * 60000;
			self::assertSame($legacy->getNoise2D($x, $z), $native->getNoise2D($x, $z));
			self::assertSame($legacy->getNoise3D($x, $y, $z), $native->getNoise3D($x, $y, $z));
			self::assertSame($legacy->noise2D($x, $z, true), $native->noise2D($x, $z, true));
			self::assertSame($legacy->noise3D($x, $y, $z), $native->noise3D($x, $y, $z));
			$ix = $points->nextBoundedInt(200000) - 100000;
			$iz = $points->nextBoundedInt(200000) - 100000;
			self::assertSame($legacy->noise2D($ix, $iz, true), $native->noise2D($ix, $iz, true), "integer coordinates like BiomeSelector passes");
		}

		foreach([[0, 0], [-16, 32], [1600, -4800], [-30000000, 29999984]] as [$chunkX, $chunkZ]){
			self::assertSame(
				$legacy->getFastNoise3D(16, 128, 16, 4, 8, 4, $chunkX, 0, $chunkZ),
				$native->getFastNoise3D(16, 128, 16, 4, 8, 4, $chunkX, 0, $chunkZ)
			);
			self::assertSame(
				$legacy->getFastNoise3D(16, 384, 16, 4, 8, 4, $chunkX, -64, $chunkZ),
				$native->getFastNoise3D(16, 384, 16, 4, 8, 4, $chunkX, -64, $chunkZ)
			);
			self::assertEquals($legacy->getFastNoise2D(16, 16, 4, $chunkX, 0, $chunkZ), $native->getFastNoise2D(16, 16, 4, $chunkX, 0, $chunkZ));
		}
	}
}
