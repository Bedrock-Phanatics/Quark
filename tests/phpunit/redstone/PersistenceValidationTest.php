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

namespace quark\world\redstone;

use PHPUnit\Framework\TestCase;
use pocketmine\nbt\tag\CompoundTag;
use quark\block\Piston;
use quark\block\tile\Comparator;
use quark\block\tile\piston\PistonArm;
use quark\block\tile\Tile;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

final class PersistenceValidationTest extends TestCase{
	private function newPistonArm() : PistonArm{
		$arm = (new ReflectionClass(PistonArm::class))->newInstanceWithoutConstructor();
		self::assertInstanceOf(PistonArm::class, $arm);
		(new ReflectionProperty(Tile::class, 'closed'))->setValue($arm, true);
		return $arm;
	}

	public function testComparatorSignalIsClamped() : void{
		$comparator = (new ReflectionClass(Comparator::class))->newInstanceWithoutConstructor();
		self::assertInstanceOf(Comparator::class, $comparator);
		(new ReflectionProperty(Tile::class, 'closed'))->setValue($comparator, true);
		$comparator->setSignalStrength(-1);
		self::assertSame(0, $comparator->getSignalStrength());
		$comparator->setSignalStrength(99);
		self::assertSame(15, $comparator->getSignalStrength());
	}

	public function testNativePistonStateTakesPrecedence() : void{
		$arm = $this->newPistonArm();
		$nbt = CompoundTag::create()->setByte('_state', Piston::STATE_RETRACT_WAITING)->setByte('State', PistonArm::BE_STATE_PUSHING)->setFloat('Progress', 0.5);
		$arm->readSaveData($nbt);
		self::assertSame(Piston::STATE_RETRACT_WAITING, $arm->state);
	}

	public function testExternalPullingStateDoesNotBecomePushing() : void{
		$arm = $this->newPistonArm();
		$nbt = CompoundTag::create()->setByte('State', PistonArm::BE_STATE_PULLING)->setFloat('Progress', 0.5);
		$arm->readSaveData($nbt);
		self::assertSame(Piston::STATE_RETRACT_WAITING, $arm->state);
	}

	public function testRetractionUsesPullingClientState() : void{
		$arm = $this->newPistonArm();
		$arm->state = Piston::STATE_RETRACT_BEGIN;
		$nbt = CompoundTag::create();
		$writer = new ReflectionMethod(PistonArm::class, 'addAdditionalSpawnData');
		$writer->invoke($arm, $nbt);
		self::assertSame(PistonArm::BE_STATE_PULLING, $nbt->getByte('State'));
	}
}
