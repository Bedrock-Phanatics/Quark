<?php

/*
 * This file is part of Quark.
 * Copyright (C) Quark Team <https://github.com/Bedrock-Phanatics/Quark>
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace quark\camera;

use quark\math\Vector2;
use quark\math\Vector3;
use function is_finite;

final class CameraValidation{

	public static function number(float $value, float $min, float $max, string $name) : void{
		if(!is_finite($value) || $value < $min || $value > $max){
			throw new \InvalidArgumentException("$name must be finite and between $min and $max");
		}
	}

	public static function vector2(Vector2 $value) : void{
		self::number($value->x, -30_000_000, 30_000_000, "Vector x");
		self::number($value->y, -30_000_000, 30_000_000, "Vector y");
	}

	public static function vector3(Vector3 $value) : void{
		self::number($value->x, -30_000_000, 30_000_000, "Vector x");
		self::number($value->y, -30_000_000, 30_000_000, "Vector y");
		self::number($value->z, -30_000_000, 30_000_000, "Vector z");
	}

	public static function duration(float $seconds) : void{
		self::number($seconds, 0, 3600, "Duration");
	}
}
