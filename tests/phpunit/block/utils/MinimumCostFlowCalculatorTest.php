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

use PHPUnit\Framework\TestCase;
use quark\block\Block;
use quark\block\Liquid;
use quark\block\VanillaBlocks;
use quark\math\Facing;
use quark\world\redstone\RedstoneHarness;
use quark\world\World;
use function array_fill_keys;
use function intdiv;
use function min;
use function mt_rand;
use function mt_srand;

final class MinimumCostFlowCalculatorTest extends TestCase{

	/**
	 * The previous depth-first calculator, kept as the reference the breadth-first one must agree with.
	 *
	 * @phpstan-param \Closure(Block) : bool $canFlowInto
	 * @return int[]
	 */
	private static function reference(World $world, int $flowDecayPerBlock, \Closure $canFlowInto, int $originX, int $originY, int $originZ) : array{
		$visited = [];
		$status = function(int $x, int $y, int $z) use ($world, $canFlowInto, &$visited) : int{
			return $visited[World::blockHash($x, $y, $z)] ??= (!$world->isInWorld($x, $y, $z) || !$canFlowInto($world->getBlockAt($x, $y, $z)) ? -1 : ($world->getBlockAt($x, $y - 1, $z)->canBeFlowedInto() ? 1 : 0));
		};
		$calculate = function(int $blockX, int $blockY, int $blockZ, int $accumulatedCost, int $maxCost, int $originOpposite, int $lastOpposite) use (&$calculate, $status) : int{
			$cost = 1000;
			foreach(Facing::HORIZONTAL as $j){
				if($j === $originOpposite || $j === $lastOpposite){
					continue;
				}
				[$dx, , $dz] = Facing::OFFSET[$j];
				$s = $status($blockX + $dx, $blockY, $blockZ + $dz);
				if($s === -1){
					continue;
				}elseif($s === 1){
					return $accumulatedCost;
				}
				if($accumulatedCost >= $maxCost){
					continue;
				}
				$cost = min($cost, $calculate($blockX + $dx, $blockY, $blockZ + $dz, $accumulatedCost + 1, $maxCost, $originOpposite, Facing::opposite($j)));
			}
			return $cost;
		};
		$flowCost = array_fill_keys(Facing::HORIZONTAL, 1000);
		$maxCost = intdiv(4, $flowDecayPerBlock);
		foreach(Facing::HORIZONTAL as $j){
			[$dx, , $dz] = Facing::OFFSET[$j];
			$s = $status($x = $originX + $dx, $originY, $z = $originZ + $dz);
			if($s === 1){
				$flowCost[$j] = $maxCost = 0;
			}elseif($s === 0 && $maxCost > 0){
				$opposite = Facing::opposite($j);
				$flowCost[$j] = $calculate($x, $originY, $z, 1, $maxCost, $opposite, $opposite);
				$maxCost = min($maxCost, $flowCost[$j]);
			}
		}
		$minCost = min($flowCost);
		$result = [];
		foreach($flowCost as $facing => $cost){
			if($cost === $minCost){
				$result[] = $facing;
			}
		}
		return $result;
	}

	public function testMatchesDepthFirstSearchOnRandomTerrain() : void{
		$harness = new RedstoneHarness(1);
		$world = $harness->world;
		$canFlowInto = fn(Block $block) : bool => $world->isInWorld((int) $block->getPosition()->x, (int) $block->getPosition()->y, (int) $block->getPosition()->z) &&
			$block->canBeFlowedInto() && !($block instanceof Liquid && $block->isSource());
		$palette = [VanillaBlocks::AIR(), VanillaBlocks::STONE(), VanillaBlocks::STONE(), VanillaBlocks::WATER(), VanillaBlocks::WATER()->setDecay(3), VanillaBlocks::TALL_GRASS()];
		mt_srand(1234);
		$checked = 0;
		//the last origin sits on a chunk edge whose neighbour is sometimes unloaded, which reads as air
		foreach([[0, 0], [0, World::Y_MIN], [0, World::Y_MAX - 1], [15, 0]] as [$originX, $y]){
			for($round = 0; $round < 700; ++$round){
				$unloaded = $originX === 15 && $round % 2 === 1;
				if($unloaded && $world->isChunkLoaded(1, 0)){
					$harness->unloadChunk(1, 0);
				}elseif(!$unloaded && !$world->isChunkLoaded(1, 0)){
					$harness->loadChunk(1, 0);
				}
				$density = mt_rand(0, 3);
				for($x = $originX - 6; $x <= $originX + 6; ++$x){
					if($unloaded && $x >= 16){
						continue;
					}
					for($z = -6; $z <= 6; ++$z){
						$world->setBlockAt($x, $y, $z, $palette[mt_rand(0, 5)], false);
						if($y > World::Y_MIN){
							$world->setBlockAt($x, $y - 1, $z, mt_rand(0, 3) < $density ? VanillaBlocks::STONE() : VanillaBlocks::AIR(), false);
						}
					}
				}
				foreach([1, 2] as $decayPerBlock){
					$expected = self::reference($world, $decayPerBlock, $canFlowInto, $originX, $y, 0);
					$actual = (new MinimumCostFlowCalculator($world, $decayPerBlock, $canFlowInto))->getOptimalFlowDirections($originX, $y, 0);
					self::assertSame($expected, $actual, "round $round x=$originX y=$y decay=$decayPerBlock");
					++$checked;
				}
			}
		}
		self::assertSame(5600, $checked);
	}

	public function testReusedCalculatorStartsFresh() : void{
		$world = (new RedstoneHarness(1))->world;
		$canFlowInto = fn(Block $block) : bool => $block->canBeFlowedInto();
		for($x = -6; $x <= 6; ++$x){
			for($z = -6; $z <= 6; ++$z){
				$world->setBlockAt($x, 0, $z, VanillaBlocks::STONE(), false);
			}
		}
		$calculator = new MinimumCostFlowCalculator($world, 1, $canFlowInto);
		self::assertSame(Facing::HORIZONTAL, $calculator->getOptimalFlowDirections(0, 1, 0));
		$world->setBlockAt(3, 0, 0, VanillaBlocks::AIR(), false);
		self::assertSame([Facing::EAST], $calculator->getOptimalFlowDirections(0, 1, 0));
	}
}
