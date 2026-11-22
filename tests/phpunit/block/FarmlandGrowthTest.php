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
use quark\block\utils\CropGrowthHelper;
use quark\world\redstone\RedstoneHarness;
use quark\world\World;
use function count;
use function mt_rand;
use function mt_srand;

final class FarmlandGrowthTest extends TestCase{
	private const Y = 64;

	/** Random farmland, water and crops spanning chunk borders, the same for every seed. */
	private static function world(int $seed) : World{
		$h = new RedstoneHarness(1);
		$ground = [
			VanillaBlocks::FARMLAND(), VanillaBlocks::FARMLAND()->setWetness(7), VanillaBlocks::FARMLAND()->setWetness(3),
			VanillaBlocks::DIRT(), VanillaBlocks::STONE(), VanillaBlocks::AIR(), VanillaBlocks::WATER(), VanillaBlocks::WATER()->setDecay(3)
		];
		$plants = [VanillaBlocks::WHEAT(), VanillaBlocks::WHEAT(), VanillaBlocks::CARROTS(), VanillaBlocks::AIR(), VanillaBlocks::WATER()];
		mt_srand($seed);
		for($x = -14; $x < 14; ++$x){
			for($z = -14; $z < 14; ++$z){
				//sparse water keeps both outcomes of the hydration search common
				$h->set($x, self::Y, $z, mt_rand(0, 9) === 0 ? $ground[mt_rand(6, 7)] : $ground[mt_rand(0, 5)]);
				$h->set($x, self::Y + 1, $z, mt_rand(0, 19) === 0 ? $plants[4] : $plants[mt_rand(0, count($plants) - 2)]);
			}
		}
		return $h->world;
	}

	/** The multiplier as it was computed from positioned blocks, kept as the reference. */
	private static function referenceMultiplier(Block $block) : float{
		$world = $block->getPosition()->getWorld();
		[$baseX, $baseY, $baseZ] = [$block->getPosition()->getFloorX(), $block->getPosition()->getFloorY(), $block->getPosition()->getFloorZ()];
		$result = 1;
		$farmland = $world->getBlockAt($baseX, $baseY - 1, $baseZ);
		if($farmland instanceof Farmland){
			$result += $farmland->getWetness() > 0 ? 3 : 1;
		}
		$xRow = $zRow = $improper = false;
		for($x = -1; $x <= 1; $x++){
			for($z = -1; $z <= 1; $z++){
				if(($x === 0 && $z === 0) || !($next = $world->getBlockAt($baseX + $x, $baseY - 1, $baseZ + $z)) instanceof Farmland){
					continue;
				}
				$result += $next->getWetness() > 0 ? 3 / 4 : 1 / 4;
				if(!$improper && $world->getBlockAt($baseX + $x, $baseY, $baseZ + $z)->hasSameTypeId($block)){
					match(0){
						$x => $zRow ? $improper = true : $xRow = true,
						$z => $xRow ? $improper = true : $zRow = true,
						default => $improper = true,
					};
				}
			}
		}
		return $improper ? $result / 2 : $result;
	}

	private static function referenceCanHydrate(Farmland $farmland) : bool{
		$pos = $farmland->getPosition();
		for($y = 0; $y < 2; $y++){
			for($x = 0; $x < 9; $x++){
				for($z = 0; $z < 9; $z++){
					if($pos->getWorld()->getBlockAt($pos->getFloorX() - 4 + $x, $pos->getFloorY() + $y, $pos->getFloorZ() - 4 + $z) instanceof Water){
						return true;
					}
				}
			}
		}
		return false;
	}

	public function testMultiplierAndHydrationMatchPositionedBlocks() : void{
		$canHydrate = \Closure::bind(fn(Farmland $farmland) : bool => $farmland->canHydrate(), null, Farmland::class);
		$crops = $farmlands = 0;
		$searched = [];
		foreach([1, 2, 3] as $seed){
			$world = self::world($seed);
			for($x = -14; $x < 14; ++$x){
				for($z = -14; $z < 14; ++$z){
					$plant = $world->getBlockAt($x, self::Y + 1, $z, cached: false, addToCache: false);
					if($plant instanceof Crops){
						self::assertSame(self::referenceMultiplier($plant), CropGrowthHelper::calculateMultiplier($plant), "crop at $x $z");
						++$crops;
					}
					$ground = $world->getBlockAt($x, self::Y, $z, cached: false, addToCache: false);
					if($ground instanceof Farmland){
						$expected = self::referenceCanHydrate($ground);
						self::assertSame($expected, $canHydrate($ground), "farmland at $x $z");
						//the second search starts from the cached water position
						self::assertSame($expected, $canHydrate($ground), "farmland at $x $z, cached");
						$searched[] = $ground;
						++$farmlands;
					}
				}
			}
			for($x = -14; $x < 14; ++$x){
				for($z = -14; $z < 14; ++$z){
					for($y = self::Y; $y <= self::Y + 1; ++$y){
						if($world->getBlockAt($x, $y, $z) instanceof Water){
							$world->setBlockAt($x, $y, $z, VanillaBlocks::AIR(), false);
						}
					}
				}
			}
			foreach($searched as $ground){
				self::assertFalse($canHydrate($ground), "farmland at " . $ground->getPosition() . " without water");
			}
			$searched = [];
		}
		self::assertGreaterThan(500, $crops);
		self::assertGreaterThan(500, $farmlands);
	}
}
