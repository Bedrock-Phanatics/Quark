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
use pocketmine\math\Facing;
use quark\block\RedstoneLamp;
use quark\block\RedstoneRepeater;
use quark\block\utils\redstone\RedstoneRepeaterBlockData;
use quark\block\VanillaBlocks;

final class RedstoneStateTest extends TestCase{

	private RedstoneHarness $h;

	protected function setUp() : void{
		$this->h = new RedstoneHarness();
		for($x = -4; $x <= 8; ++$x){
			for($z = -4; $z <= 4; ++$z){
				$this->h->set($x, 0, $z, VanillaBlocks::STONE());
			}
		}
		$this->h->tick();
	}

	private function placeRepeater(int $delay = 1) : void{
		$this->h->place(2, 1, 0, VanillaBlocks::REDSTONE_REPEATER()->setFacing(Facing::WEST)->setDelay($delay));
		$this->h->place(3, 1, 0, VanillaBlocks::REDSTONE_LAMP());
		$this->h->tick();
	}

	private function repeaterPowered() : bool{
		$repeater = $this->h->get(2, 1, 0);
		self::assertInstanceOf(RedstoneRepeater::class, $repeater);
		return $repeater->isPowered();
	}

	private function lampLit() : bool{
		$lamp = $this->h->get(3, 1, 0);
		self::assertInstanceOf(RedstoneLamp::class, $lamp);
		return $lamp->isPowered();
	}

	public function testRepeaterDrivesLampAfterItsDelay() : void{
		$this->placeRepeater(4);
		$this->h->place(1, 1, 0, VanillaBlocks::REDSTONE());
		$this->h->tick(4);
		self::assertFalse($this->repeaterPowered(), "a 4 tick repeater waits 8 game ticks");
		$this->h->tick(6);
		self::assertTrue($this->repeaterPowered());
		self::assertTrue($this->lampLit());
		$this->h->tick(10);
		self::assertSame(0, $this->h->state()->getDataChunkCount(), "settled repeaters keep no data");
	}

	public function testReplacingRepeaterDropsItsPendingData() : void{
		$this->placeRepeater(4);
		$this->h->place(1, 1, 0, VanillaBlocks::REDSTONE());
		$this->h->tick();
		self::assertNotNull($this->h->state()->getExtraDataAt(2, 1, 0));

		$this->h->set(2, 1, 0, VanillaBlocks::STONE());
		self::assertNull($this->h->state()->getExtraDataAt(2, 1, 0));
		self::assertSame(0, $this->h->state()->getDataChunkCount());
		self::assertCount(0, $this->h->world->getChunkListeners(0, 0), "an empty data chunk stops listening");
	}

	public function testChunkUnloadDropsRedstoneData() : void{
		$this->placeRepeater(4);
		$this->h->place(1, 1, 0, VanillaBlocks::REDSTONE());
		$this->h->tick();
		self::assertSame(1, $this->h->state()->getDataChunkCount());

		$this->h->unloadChunk(0, 0);
		self::assertSame(0, $this->h->state()->getDataChunkCount());
	}

	public function testRepeaterSettlesAfterItsChunkReloads() : void{
		$this->placeRepeater(4);
		$this->h->place(1, 1, 0, VanillaBlocks::REDSTONE());
		$this->h->tick();
		$chunk = $this->h->unloadChunk(0, 0);
		$this->h->tick(20);

		$this->h->loadChunk(0, 0, $chunk);
		$this->h->tick(20);
		self::assertTrue($this->repeaterPowered(), "the deferred update runs even though the chunk's data was dropped");
		self::assertTrue($this->lampLit());
	}

	public function testStaleRepeaterDataDoesNotFreezeTheRepeater() : void{
		$this->placeRepeater();
		$this->h->tick(5);
		//an operation whose scheduled update was lost
		$this->h->state()->setExtraDataAt(2, 1, 0, new RedstoneRepeaterBlockData(RedstoneRepeaterBlockData::OPERATION_SWITCH_RECALCULATE));
		$this->h->place(1, 1, 0, VanillaBlocks::REDSTONE());
		$this->h->tick(10);
		self::assertTrue($this->repeaterPowered());
		self::assertTrue($this->lampLit());
	}
}
