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

namespace quark\world;

use PHPUnit\Framework\TestCase;
use quark\entity\Location;
use quark\math\Facing;
use quark\math\Vector3;
use quark\world\redstone\RedstoneHarness;
use function array_keys;
use function array_map;
use function serialize;
use function unserialize;

final class PositionLocationTest extends TestCase{
	private World $world;

	protected function setUp() : void{
		$this->world = (new RedstoneHarness(0))->world;
	}

	public function testSideHelpersKeepTheSubclassAndWorld() : void{
		$pos = new Position(1, 64, -3, $this->world);
		foreach([$pos->up(), $pos->down(2), $pos->north(), $pos->getSide(Facing::EAST, 3)] as $side){
			self::assertInstanceOf(Position::class, $side);
			self::assertSame($this->world, $side->world);
		}
		self::assertSame([1, 66, -3], [$pos->up(2)->x, $pos->up(2)->y, $pos->up(2)->z]);
		self::assertSame(Facing::ALL, array_keys($pos->sides()));
		self::assertSame([Position::class], array_map(fn(Vector3 $v) => $v::class, [$pos->sides()[Facing::SOUTH]]));

		//arithmetic returns plain vectors, like before
		self::assertSame(Vector3::class, $pos->add(1, 0, 0)::class);
		self::assertSame(Vector3::class, $pos->asVector3()::class);
		self::assertSame(Position::class, $pos->asPosition()::class);
	}

	public function testDirectWritesAreSeenByNativeMethods() : void{
		$loc = new Location(0.5, 64, 0.5, $this->world, 90, 10);
		$loc->x = 10.75;
		$loc->z = -3.25;
		self::assertSame([10, -4], [$loc->getFloorX(), $loc->getFloorZ()]);
		self::assertSame(11.75, $loc->add(1, 0, 0)->x);
		self::assertSame((10.75 ** 2 + 64 ** 2 + 3.25 ** 2), $loc->lengthSquared());
		self::assertSame([90.0, 10.0], [$loc->yaw, $loc->pitch]);
	}

	public function testCloneIsIndependent() : void{
		$loc = new Location(1, 2, 3, $this->world, 45, 5);
		$copy = clone $loc;
		$copy->x = 100;
		$copy->yaw = 0;
		self::assertSame([1.0, 45.0], [$loc->x, $loc->yaw]);
		self::assertSame($this->world, $copy->world);
		self::assertTrue($loc->equals(new Location(1, 2, 3, $this->world, 45, 5)));
		self::assertFalse($loc->equals(new Location(1, 2, 3, $this->world, 46, 5)));
		self::assertFalse((new Position(1, 2, 3, $this->world))->equals(new Position(1, 2, 3, null)));
		self::assertTrue((new Position(1, 2, 3, $this->world))->equals(new Vector3(1, 2, 3)));
	}

	public function testLocationConversions() : void{
		$pos = new Position(4, 5, 6, $this->world);
		$loc = Location::fromObject($pos, null, 30, 40);
		self::assertSame($this->world, $loc->world);
		self::assertSame([4.0, 5.0, 6.0, 30.0, 40.0], [$loc->x, $loc->y, $loc->z, $loc->yaw, $loc->pitch]);
		self::assertSame(Location::class, $loc->asLocation()::class);
		self::assertSame(Position::class, $loc->asPosition()::class);
		self::assertStringContainsString("yaw=30", (string) $loc);
	}

	public function testPlainVectorsSerializeAndCompareByValue() : void{
		$v = new Vector3(1, 2.5, -3);
		$copy = unserialize(serialize($v));
		self::assertInstanceOf(Vector3::class, $copy);
		self::assertTrue($v->equals($copy));
		self::assertSame([1, 2.5, -3], [$copy->x, $copy->y, $copy->z]);
	}

	public function testUnloadedWorldsAreRejected() : void{
		(new \ReflectionProperty(World::class, "unloaded"))->setValue($this->world, true);
		$this->expectException(\InvalidArgumentException::class);
		new Position(0, 0, 0, $this->world);
	}
}
