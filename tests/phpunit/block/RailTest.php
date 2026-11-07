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

namespace quark\block;

use PHPUnit\Framework\TestCase;
use quark\block\utils\RailShape;
use quark\block\utils\StraightOnlyRailShape;
use quark\data\bedrock\block\BlockStateNames;
use quark\data\bedrock\block\convert\BlockObjectToStateSerializer;
use quark\data\bedrock\block\convert\BlockStateToObjectDeserializer;
use quark\world\format\io\GlobalBlockStateHandlers;
use function array_map;
use function array_unique;
use function count;

final class RailTest extends TestCase{
	private const BEDROCK_RAIL_DIRECTION = [
		'FLAT_AXIS_Z' => 0,
		'FLAT_AXIS_X' => 1,
		'ASCENDING_EAST' => 2,
		'ASCENDING_WEST' => 3,
		'ASCENDING_NORTH' => 4,
		'ASCENDING_SOUTH' => 5,
		'CURVED_SOUTHEAST' => 6,
		'CURVED_SOUTHWEST' => 7,
		'CURVED_NORTHWEST' => 8,
		'CURVED_NORTHEAST' => 9
	];

	private BlockStateToObjectDeserializer $deserializer;
	private BlockObjectToStateSerializer $serializer;

	public function setUp() : void{
		VanillaBlocks::AIR();
		$this->deserializer = GlobalBlockStateHandlers::getDeserializer();
		$this->serializer = GlobalBlockStateHandlers::getSerializer();
	}

	public function testRailShapesMapToBedrockRailDirection() : void{
		foreach(RailShape::cases() as $shape){
			$state = $this->serializer->serializeBlock(VanillaBlocks::RAIL()->setShape($shape));
			self::assertSame(self::BEDROCK_RAIL_DIRECTION[$shape->name], $state->getState(BlockStateNames::RAIL_DIRECTION)?->getValue(), $shape->name);

			$restored = $this->deserializer->deserializeBlock($state);
			self::assertInstanceOf(Rail::class, $restored);
			self::assertSame($shape, $restored->getShape());
		}
	}

	public function testStraightOnlyRailShapesMapToBedrockRailDirection() : void{
		foreach([VanillaBlocks::POWERED_RAIL(), VanillaBlocks::DETECTOR_RAIL(), VanillaBlocks::ACTIVATOR_RAIL()] as $block){
			foreach(StraightOnlyRailShape::cases() as $shape){
				$state = $this->serializer->serializeBlock($block->setShape($shape));
				self::assertSame(self::BEDROCK_RAIL_DIRECTION[$shape->name], $state->getState(BlockStateNames::RAIL_DIRECTION)?->getValue(), $block->getName() . " " . $shape->name);

				$restored = $this->deserializer->deserializeBlock($state);
				self::assertInstanceOf(StraightOnlyRail::class, $restored);
				self::assertSame($shape, $restored->getShape());
			}
		}
	}

	public function testStateIdRoundTrip() : void{
		$factory = RuntimeBlockStateRegistry::getInstance();
		foreach(RailShape::cases() as $shape){
			$restored = $factory->fromStateId(VanillaBlocks::RAIL()->setShape($shape)->getStateId());
			self::assertInstanceOf(Rail::class, $restored);
			self::assertSame($shape, $restored->getShape());
		}
		foreach(StraightOnlyRailShape::cases() as $shape){
			$restored = $factory->fromStateId(VanillaBlocks::POWERED_RAIL()->setShape($shape)->getStateId());
			self::assertInstanceOf(PoweredRail::class, $restored);
			self::assertSame($shape, $restored->getShape());
		}
	}

	public function testShapeConnectionsAreDistinct() : void{
		$connections = array_map(fn(RailShape $s) => $s->getConnections(), RailShape::cases());
		foreach($connections as $c){
			self::assertCount(2, $c);
		}
		self::assertCount(count(RailShape::cases()), array_unique(array_map(fn(array $c) => $c[0] . ":" . $c[1], $connections)));

		foreach(StraightOnlyRailShape::cases() as $shape){
			self::assertSame(RailShape::{$shape->name}->getConnections(), $shape->getConnections());
		}
	}
}
