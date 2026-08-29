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

namespace quark\block\utils;

use quark\data\runtime\RuntimeDataDescriber;
use function floor;

trait SignLikeRotationTrait{
	/** @var int */
	private $rotation = 0;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(0, 15, $this->rotation);
	}

	public function getRotation() : int{ return $this->rotation; }

	/** @return $this */
	public function setRotation(int $rotation) : self{
		if($rotation < 0 || $rotation > 15){
			throw new \InvalidArgumentException("Rotation must be in range 0-15");
		}
		$this->rotation = $rotation;
		return $this;
	}

	private static function getRotationFromYaw(float $yaw) : int{
		return ((int) floor((($yaw + 180) * 16 / 360) + 0.5)) & 0xf;
	}
}
