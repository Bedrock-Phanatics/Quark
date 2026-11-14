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
use pocketmine\world\format\LightArray;
use quark\block\utils\BlockEventHelper;
use quark\block\utils\DirtType;
use quark\world\redstone\RedstoneHarness;
use quark\world\World;
use function count;
use function mt_rand;
use function mt_srand;

final class GrassSpreadTest extends TestCase{
	private const MIN_Y = 58;
	private const MAX_Y = 66;

	/** Builds the same mixed terrain with patchy sky light for every seed. */
	private static function world(int $seed) : RedstoneHarness{
		$h = new RedstoneHarness(1);
		$palette = [
			VanillaBlocks::DIRT(), VanillaBlocks::DIRT(), VanillaBlocks::DIRT()->setDirtType(DirtType::COARSE), VanillaBlocks::GRASS(), VanillaBlocks::GRASS(),
			VanillaBlocks::STONE(), VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::AIR(), VanillaBlocks::OAK_LEAVES(), VanillaBlocks::GLASS(), VanillaBlocks::SNOW_LAYER()
		];
		mt_srand($seed);
		for($x = -12; $x < 12; ++$x){
			for($z = -12; $z < 12; ++$z){
				for($y = self::MIN_Y; $y <= self::MAX_Y; ++$y){
					$h->set($x, $y, $z, $palette[mt_rand(0, count($palette) - 1)]);
				}
			}
		}
		for($cx = -1; $cx <= 1; ++$cx){
			for($cz = -1; $cz <= 1; ++$cz){
				$chunk = $h->world->getChunk($cx, $cz);
				self::assertNotNull($chunk);
				$chunk->setLightPopulated(true);
				foreach([3, 4] as $sy){
					$light = LightArray::fill(0);
					for($i = 0; $i < 4096; ++$i){
						$light->set($i & 15, ($i >> 4) & 15, $i >> 8, mt_rand(0, 15));
					}
					$chunk->getSubChunk($sy)->setBlockSkyLightArray($light);
				}
			}
		}
		return $h;
	}

	/** The spread logic before it read raw states, kept as the reference. */
	private static function referenceTick(Grass $grass) : void{
		$pos = $grass->getPosition();
		$world = $pos->getWorld();
		[$px, $py, $pz] = [$pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ()];
		$lightAbove = $world->getFullLightAt($px, $py + 1, $pz);
		if($lightAbove < 4 && $world->getBlockAt($px, $py + 1, $pz)->getLightFilter() >= 2){
			BlockEventHelper::spread($grass, VanillaBlocks::DIRT(), $grass);
		}elseif($lightAbove >= 9){
			for($i = 0; $i < 4; ++$i){
				$x = mt_rand($px - 1, $px + 1);
				$y = mt_rand($py - 3, $py + 1);
				$z = mt_rand($pz - 1, $pz + 1);
				$b = $world->getBlockAt($x, $y, $z);
				if(
					!($b instanceof Dirt) ||
					$b->getDirtType() !== DirtType::NORMAL ||
					$world->getFullLightAt($x, $y + 1, $z) < 4 ||
					$world->getBlockAt($x, $y + 1, $z)->getLightFilter() >= 2
				){
					continue;
				}
				BlockEventHelper::spread($b, VanillaBlocks::GRASS(), $grass);
			}
		}
	}

	/** @return list<int> */
	private static function states(World $world) : array{
		$states = [];
		for($x = -14; $x < 14; ++$x){
			for($z = -14; $z < 14; ++$z){
				for($y = self::MIN_Y - 4; $y <= self::MAX_Y + 2; ++$y){
					$states[] = $world->getBlockAt($x, $y, $z, cached: false, addToCache: false)->getStateId();
				}
			}
		}
		return $states;
	}

	public function testSpreadMatchesTheBlockObjectReference() : void{
		$actual = self::world(7);
		$expected = self::world(7);
		$spreads = 0;
		for($i = 0; $i < 6000; ++$i){
			mt_srand(1000 + $i);
			$x = mt_rand(-13, 12);
			$y = mt_rand(self::MIN_Y, self::MAX_Y);
			$z = mt_rand(-13, 12);
			$a = $actual->world->getBlockAt($x, $y, $z);
			$e = $expected->world->getBlockAt($x, $y, $z);
			self::assertSame($e->getStateId(), $a->getStateId());
			if(!$a instanceof Grass || !$e instanceof Grass){
				continue;
			}
			mt_srand(5000 + $i);
			$a->onRandomTick();
			mt_srand(5000 + $i);
			self::referenceTick($e);
			++$spreads;
		}
		self::assertGreaterThan(500, $spreads);
		$after = self::states($expected->world);
		self::assertNotSame(self::states(self::world(7)->world), $after, "grass should have spread and died during the run");
		self::assertSame($after, self::states($actual->world));
	}

	public function testSpreadsOnlyOntoLitNormalDirt() : void{
		$h = new RedstoneHarness(1);
		$h->set(0, 64, 0, VanillaBlocks::GRASS());
		foreach([[1, 64, 0], [-1, 64, 0], [0, 64, 1], [0, 64, -1]] as [$x, $y, $z]){
			$h->set($x, $y, $z, VanillaBlocks::DIRT());
		}
		$h->set(-1, 64, 0, VanillaBlocks::DIRT()->setDirtType(DirtType::COARSE));
		$h->set(0, 65, 1, VanillaBlocks::STONE());
		$chunk = $h->world->getChunk(0, 0);
		$west = $h->world->getChunk(-1, 0);
		self::assertNotNull($chunk);
		self::assertNotNull($west);
		foreach([$chunk, $west, $h->world->getChunk(0, -1), $h->world->getChunk(-1, -1)] as $c){
			self::assertNotNull($c);
			$c->setLightPopulated(true);
			$c->getSubChunk(4)->setBlockSkyLightArray(LightArray::fill(15));
		}
		$grass = $h->world->getBlockAt(0, 64, 0);
		self::assertInstanceOf(Grass::class, $grass);
		for($i = 0; $i < 400; ++$i){
			$grass->onRandomTick();
		}
		self::assertInstanceOf(Grass::class, $h->get(1, 64, 0));
		self::assertInstanceOf(Grass::class, $h->get(0, 64, -1));
		self::assertInstanceOf(Dirt::class, $h->get(-1, 64, 0), "coarse dirt never turns to grass");
		self::assertInstanceOf(Dirt::class, $h->get(0, 64, 1), "dirt under an opaque block stays dirt");
	}
}
