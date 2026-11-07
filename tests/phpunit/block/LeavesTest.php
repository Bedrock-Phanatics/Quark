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
use pocketmine\world\format\PalettedBlockArray;
use quark\data\bedrock\BiomeIds;
use quark\utils\Random;
use quark\world\format\Chunk;
use quark\world\format\SubChunk;
use quark\world\generator\object\AcaciaTree;
use quark\world\SimpleChunkManager;
use quark\world\World;

final class LeavesTest extends TestCase{
	private SimpleChunkManager $world;

	protected function setUp() : void{
		$air = VanillaBlocks::AIR()->getStateId();
		$this->world = new SimpleChunkManager(World::Y_MIN, World::Y_MAX);
		foreach([-1, 0] as $chunkX){
			foreach([-1, 0] as $chunkZ){
				$subChunks = [4 => new SubChunk($air, [new PalettedBlockArray($air)], new PalettedBlockArray(BiomeIds::PLAINS))];
				$this->world->setChunk($chunkX, $chunkZ, new Chunk($subChunks, true));
			}
		}
	}

	public function testGrownAcaciaCanopyKeepsAllLeaves() : void{
		$random = new Random(5821);
		for($i = 0; $i < 300; ++$i){
			$transaction = (new AcaciaTree())->getBlockTransaction($this->world, 0, 64, 0, $random);
			self::assertNotNull($transaction);
			self::assertTrue($transaction->apply());

			$leaves = 0;
			for($x = -8; $x <= 8; ++$x){
				for($y = 64; $y <= 80; ++$y){
					for($z = -8; $z <= 8; ++$z){
						if($this->world->getBlockAt($x, $y, $z) instanceof Leaves){
							++$leaves;
							self::assertTrue(Leaves::isConnectedToLog($this->world, $x, $y, $z), "tree $i leaf $x,$y,$z");
						}
					}
				}
			}
			self::assertGreaterThan(0, $leaves);

			for($x = -8; $x <= 8; ++$x){
				for($y = 64; $y <= 80; ++$y){
					for($z = -8; $z <= 8; ++$z){
						$this->world->setBlockAt($x, $y, $z, VanillaBlocks::AIR());
					}
				}
			}
		}
	}

	public function testShortRouteIsFoundAfterLongerDetours() : void{
		$leaf = VanillaBlocks::OAK_LEAVES();
		for($x = 1; $x <= 5; ++$x){
			$this->world->setBlockAt($x, 64, 0, $leaf);
		}
		$this->world->setBlockAt(6, 64, 0, VanillaBlocks::OAK_LOG());
		//a pocket of leaves the search can wander into before trying the straight line
		for($x = 0; $x <= 5; ++$x){
			for($z = 1; $z <= 3; ++$z){
				$this->world->setBlockAt($x, 64, $z, $leaf);
			}
		}

		self::assertTrue(Leaves::isConnectedToLog($this->world, 1, 64, 0));
		self::assertTrue(Leaves::isConnectedToLog($this->world, 5, 64, 3));
		self::assertFalse(Leaves::isConnectedToLog($this->world, 0, 64, 3), "seven blocks from the log");
	}

	public function testDistantLeafWithoutLogDecays() : void{
		$leaf = VanillaBlocks::OAK_LEAVES();
		for($x = 0; $x <= 6; ++$x){
			$this->world->setBlockAt($x, 64, 0, $leaf);
		}
		$this->world->setBlockAt(7, 64, 0, VanillaBlocks::OAK_LOG());

		self::assertTrue(Leaves::isConnectedToLog($this->world, 2, 64, 0));
		self::assertFalse(Leaves::isConnectedToLog($this->world, 1, 64, 0));
	}

	public function testWorldLimitsAreSafe() : void{
		$this->world->setBlockAt(0, World::Y_MAX - 1, 0, VanillaBlocks::OAK_LEAVES());
		self::assertFalse(Leaves::isConnectedToLog($this->world, 0, World::Y_MAX - 1, 0));
	}
}
