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

use quark\math\noise\Simplex as NativeSimplex;
use quark\utils\Random;

/**
 * Generates simplex-based noise.
 *
 * This is a modified version of the freely published version in the paper by
 * Stefan Gustavson at
 * http://staffwww.itn.liu.se/~stegu/simplexnoise/simplexnoise.pdf
 *
 * The permutation and offsets are drawn here so the RNG is consumed exactly as before, sampling runs natively.
 */
final class Simplex extends Noise{
	private NativeSimplex $native;

	public function __construct(Random $random, int $octaves, float $persistence, float $expansion){
		parent::__construct($octaves, $persistence, $expansion);

		$offsetX = $random->nextFloat() * 256;
		$offsetY = $random->nextFloat() * 256;
		$offsetZ = $random->nextFloat() * 256;

		$perm = [];
		for($i = 0; $i < 512; ++$i){
			$perm[$i] = 0;
		}

		for($i = 0; $i < 256; ++$i){
			$perm[$i] = $random->nextBoundedInt(256);
		}

		for($i = 0; $i < 256; ++$i){
			$pos = $random->nextBoundedInt(256 - $i) + $i;
			$old = $perm[$i];

			$perm[$i] = $perm[$pos];
			$perm[$pos] = $old;
			$perm[$i + 256] = $perm[$i];
		}

		//this dummy call is necessary to produce the same RNG state as before latest refactors to this file
		//previously this value would be used for offsetW
		//TODO: this really needs to reset the RNG seed to avoid future RNG contamination
		$random->nextSignedInt();

		/** @var list<int> $perm */
		$this->native = new NativeSimplex($perm, $offsetX, $offsetY, $offsetZ, $octaves, $persistence, $expansion);
	}

	public function getNoise3D($x, $y, $z){
		return $this->native->getNoise3D($x, $y, $z);
	}

	public function getNoise2D($x, $z){
		return $this->native->getNoise2D($x, $z);
	}

	public function noise2D($x, $z, $normalized = false){
		return $this->native->noise2D($x, $z, $normalized);
	}

	public function noise3D($x, $y, $z, $normalized = false){
		return $this->native->noise3D($x, $y, $z, $normalized);
	}

	public function getFastNoise3D(int $xSize, int $ySize, int $zSize, int $xSamplingRate, int $ySamplingRate, int $zSamplingRate, int $x, int $y, int $z) : array{
		return $this->native->getFastNoise3D($xSize, $ySize, $zSize, $xSamplingRate, $ySamplingRate, $zSamplingRate, $x, $y, $z);
	}
}
