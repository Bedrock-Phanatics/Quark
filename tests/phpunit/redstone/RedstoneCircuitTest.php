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
use pocketmine\math\Vector3;
use quark\block\RedstoneLamp;
use quark\block\RedstoneWire;
use quark\block\utils\LeverFacing;
use quark\block\VanillaBlocks;
use quark\item\VanillaItems;

final class RedstoneCircuitTest extends TestCase{

	private RedstoneHarness $h;

	protected function setUp() : void{
		$this->h = new RedstoneHarness();
		for($x = -8; $x <= 8; ++$x){
			for($z = -8; $z <= 8; ++$z){
				$this->h->set($x, 0, $z, VanillaBlocks::STONE());
			}
		}
		$this->h->tick();
	}

	private function lampLit(int $x, int $y, int $z) : bool{
		$lamp = $this->h->get($x, $y, $z);
		self::assertInstanceOf(RedstoneLamp::class, $lamp);
		return $lamp->isPowered();
	}

	private function wire(int $x, int $y, int $z) : RedstoneWire{
		$wire = $this->h->get($x, $y, $z);
		self::assertInstanceOf(RedstoneWire::class, $wire);
		return $wire;
	}

	public function testWireCarriesPowerToLamp() : void{
		for($x = 1; $x <= 3; ++$x){
			$this->h->place($x, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		}
		$this->h->place(4, 1, 0, VanillaBlocks::REDSTONE_LAMP());
		$this->h->tick(5);
		self::assertFalse($this->lampLit(4, 1, 0));

		$this->h->place(0, 1, 0, VanillaBlocks::REDSTONE());
		$this->h->tick(5);
		self::assertSame(15, $this->wire(1, 1, 0)->getOutputSignalStrength());
		self::assertSame(13, $this->wire(3, 1, 0)->getOutputSignalStrength());
		self::assertTrue($this->lampLit(4, 1, 0));

		$this->h->breakBlock(0, 1, 0);
		$this->h->tick(10);
		self::assertSame(0, $this->wire(3, 1, 0)->getOutputSignalStrength());
		self::assertFalse($this->lampLit(4, 1, 0));
	}

	public function testLeverPowersThroughItsSupportBlock() : void{
		$this->h->set(1, 1, 0, VanillaBlocks::STONE());
		$this->h->place(2, 1, 0, VanillaBlocks::LEVER()->setFacing(LeverFacing::EAST));
		$this->h->place(0, 1, 0, VanillaBlocks::REDSTONE_LAMP());
		$this->h->tick(5);
		self::assertFalse($this->lampLit(0, 1, 0));

		$this->h->get(2, 1, 0)->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0, 0, 0));
		$this->h->tick(5);
		self::assertTrue($this->lampLit(0, 1, 0), "lever strongly powers the block it's attached to");

		$this->h->get(2, 1, 0)->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0, 0, 0));
		$this->h->tick(10);
		self::assertFalse($this->lampLit(0, 1, 0));

		$this->h->get(2, 1, 0)->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0, 0, 0));
		$this->h->tick(5);
		self::assertTrue($this->lampLit(0, 1, 0));
		$this->h->breakBlock(2, 1, 0);
		$this->h->tick(10);
		self::assertFalse($this->lampLit(0, 1, 0), "breaking an active lever releases blocks powered through its support");
	}

	public function testBreakingLitTorchReleasesBlockAbove() : void{
		$this->h->set(0, 2, 0, VanillaBlocks::STONE());
		$this->h->place(1, 2, 0, VanillaBlocks::REDSTONE_LAMP());
		$this->h->place(0, 1, 0, VanillaBlocks::REDSTONE_TORCH()->setFacing(Facing::UP));
		$this->h->tick(10);
		self::assertTrue($this->lampLit(1, 2, 0));

		$this->h->breakBlock(0, 1, 0);
		$this->h->tick(10);
		self::assertFalse($this->lampLit(1, 2, 0));
	}
}
