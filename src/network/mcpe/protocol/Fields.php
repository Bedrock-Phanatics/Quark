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

namespace quark\network\mcpe\protocol;

use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use function strlen;
use function strrev;
use function substr;

/**
 * Converts game values to and from the packet field shapes in Packets.
 *
 * @phpstan-import-type Vec3f from Packets
 * @phpstan-import-type Vec2f from Packets
 * @phpstan-import-type BlockPosition from Packets
 */
final class Fields{

	private function __construct(){
		//NOOP
	}

	/**
	 * @phpstan-return Vec3f
	 */
	public static function vec3(Vector3 $vector) : array{
		return ["x" => (float) $vector->x, "y" => (float) $vector->y, "z" => (float) $vector->z];
	}

	/**
	 * @phpstan-param Vec3f $vector
	 */
	public static function toVector3(array $vector) : Vector3{
		return new Vector3($vector["x"], $vector["y"], $vector["z"]);
	}

	/**
	 * @phpstan-return Vec2f
	 */
	public static function vec2(float $x, float $y) : array{
		return ["x" => $x, "y" => $y];
	}

	/**
	 * @phpstan-param Vec2f $vector
	 */
	public static function toVector2(array $vector) : Vector2{
		return new Vector2($vector["x"], $vector["y"]);
	}

	/**
	 * @phpstan-return BlockPosition
	 */
	public static function blockPosition(Vector3 $vector) : array{
		return ["x" => $vector->getFloorX(), "y" => $vector->getFloorY(), "z" => $vector->getFloorZ()];
	}

	/**
	 * @phpstan-param BlockPosition $position
	 */
	public static function toBlockVector(array $position) : Vector3{
		return new Vector3($position["x"], $position["y"], $position["z"]);
	}

	/**
	 * Bedrock writes each half of a UUID as a little-endian 64-bit integer.
	 */
	public static function uuid(UuidInterface $uuid) : string{
		$bytes = $uuid->getBytes();
		return strrev(substr($bytes, 0, 8)) . strrev(substr($bytes, 8, 8));
	}

	public static function toUuid(string $bytes) : UuidInterface{
		if(strlen($bytes) !== 16){
			throw new \InvalidArgumentException("A UUID is 16 bytes, got " . strlen($bytes));
		}
		return Uuid::fromBytes(strrev(substr($bytes, 0, 8)) . strrev(substr($bytes, 8, 8)));
	}
}
