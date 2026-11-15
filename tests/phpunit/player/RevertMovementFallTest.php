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

namespace quark\player;

use PHPUnit\Framework\TestCase;
use quark\entity\Entity;
use quark\entity\Location;
use quark\world\World;

final class RevertMovementFallTest extends TestCase{

	/** Reverts a player standing at $currentY back to $fromY and returns the fall distance left. */
	private function revert(float $fromY, float $currentY, float $fallDistance) : float{
		$player = $this->getMockBuilder(Player::class)->disableOriginalConstructor()->onlyMethods(["setPosition", "sendPosition"])->getMock();
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		$world = $this->createMock(World::class);
		$world->method("isLoaded")->willReturn(true);
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, new Location(0, $currentY, 0, $world, 0, 0));
		$player->fallDistance = $fallDistance;
		$player->expects(self::once())->method("sendPosition");
		(new \ReflectionMethod(Player::class, "revertMovement"))->invoke($player, new Location(0, $fromY, 0, $world, 0, 0));
		return $player->fallDistance;
	}

	public function testUndoneDropIsNotCountedAgain() : void{
		//a cancelled PlayerMoveEvent while falling: the client falls the same 1.5 blocks again from $from
		self::assertSame(2.0, $this->revert(70.0, 68.5, 3.5));
	}

	public function testRepeatedRevertsInMidAirDoNotAccumulate() : void{
		$fallDistance = 0.0;
		for($i = 0; $i < 100; ++$i){
			$fallDistance = $this->revert(70.0, 69.6, $fallDistance + 0.4);
		}
		self::assertSame(0.0, $fallDistance);
	}

	public function testNeverGoesNegative() : void{
		//landed during the reverted moves, so the fall was already reset
		self::assertSame(0.0, $this->revert(70.0, 64.0, 0.0));
	}

	public function testUpwardRevertKeepsTheFall() : void{
		self::assertSame(2.0, $this->revert(69.0, 70.0, 2.0));
	}
}
