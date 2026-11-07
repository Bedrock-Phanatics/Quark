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
use quark\block\Door;
use quark\block\Hopper;
use quark\block\RedstoneComparator;
use quark\block\RedstoneLamp;
use quark\block\RedstoneRepeater;
use quark\block\RedstoneTorch;
use quark\block\RedstoneWire;
use quark\block\tile\ContainerTile;
use quark\block\utils\LeverFacing;
use quark\block\VanillaBlocks;
use quark\entity\Location;
use quark\entity\object\ItemEntity;
use quark\item\VanillaItems;
use quark\world\Explosion;
use quark\world\Position;

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

	public function testTorchInvertsAfterOneRedstoneTick() : void{
		$this->h->set(1, 1, 0, VanillaBlocks::STONE());
		$this->h->place(2, 1, 0, VanillaBlocks::REDSTONE_TORCH()->setFacing(Facing::EAST));
		$this->h->place(0, 1, 0, VanillaBlocks::LEVER()->setFacing(LeverFacing::WEST));
		$this->h->tick(5);
		$torch = $this->h->get(2, 1, 0);
		self::assertInstanceOf(RedstoneTorch::class, $torch);
		self::assertTrue($torch->isLit());

		$this->h->get(0, 1, 0)->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0, 0, 0));
		$this->h->tick();
		$torch = $this->h->get(2, 1, 0);
		self::assertInstanceOf(RedstoneTorch::class, $torch);
		self::assertTrue($torch->isLit(), "still lit one game tick later");
		$this->h->tick(2);
		$torch = $this->h->get(2, 1, 0);
		self::assertInstanceOf(RedstoneTorch::class, $torch);
		self::assertFalse($torch->isLit());
	}

	public function testOversizedWireNetworkIsLeftAlone() : void{
		RedstoneConfig::configure(3, 262144, 8192, true, true, 1024, 4096);
		for($x = 1; $x <= 5; ++$x){
			$this->h->place($x, 1, 0, VanillaBlocks::REDSTONE_WIRE());
		}
		$this->h->place(0, 1, 0, VanillaBlocks::REDSTONE());
		$this->h->tick(5);
		for($x = 1; $x <= 5; ++$x){
			self::assertSame(0, $this->wire($x, 1, 0)->getOutputSignalStrength(), "networks over the limit are not resolved");
		}

		RedstoneConfig::configure(65536, 262144, 8192, true, true, 1024, 4096);
		$this->h->set(1, 2, 0, VanillaBlocks::STONE());
		$this->h->tick(5);
		self::assertSame(15, $this->wire(1, 1, 0)->getOutputSignalStrength(), "the next update resolves it once allowed");
		self::assertSame(11, $this->wire(5, 1, 0)->getOutputSignalStrength());
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

	public function testExplodedLeverReleasesBlocksPoweredThroughItsSupport() : void{
		$this->h->set(1, 1, 0, VanillaBlocks::STONE());
		$this->h->place(2, 1, 0, VanillaBlocks::LEVER()->setFacing(LeverFacing::EAST)->setActivated(true));
		$this->h->place(0, 1, 0, VanillaBlocks::REDSTONE_LAMP());
		$this->h->tick(5);
		self::assertTrue($this->lampLit(0, 1, 0));

		$explosion = new Explosion(new Position(2.5, 1.5, 0.5, $this->h->world), 1.5);
		self::assertTrue($explosion->explodeA());
		self::assertTrue($explosion->explodeB());
		self::assertSame(VanillaBlocks::AIR()->getTypeId(), $this->h->get(2, 1, 0)->getTypeId());
		self::assertSame(VanillaBlocks::STONE()->getTypeId(), $this->h->get(1, 1, 0)->getTypeId());
		$this->h->tick(10);
		self::assertFalse($this->lampLit(0, 1, 0));
	}

	public function testPressurePlatePowersThroughItsSupport() : void{
		$this->h->set(0, 1, 0, VanillaBlocks::STONE());
		$this->h->place(1, 1, 0, VanillaBlocks::REDSTONE_LAMP());
		$this->h->place(0, 2, 0, VanillaBlocks::OAK_PRESSURE_PLATE());
		$this->h->tick();
		$item = new ItemEntity(Location::fromObject(new Vector3(0.5, 2.0, 0.5), $this->h->world), VanillaItems::STICK());
		$this->h->get(0, 2, 0)->onScheduledUpdate();
		$this->h->tick(5);
		self::assertTrue($this->lampLit(1, 1, 0));

		$item->close();
		$this->h->get(0, 2, 0)->onScheduledUpdate();
		$this->h->tick(10);
		self::assertFalse($this->lampLit(1, 1, 0));
	}

	public function testDoorOpensWhenOnlyItsTopHalfIsPowered() : void{
		$this->h->set(0, 1, 0, VanillaBlocks::OAK_DOOR());
		$this->h->set(0, 2, 0, VanillaBlocks::OAK_DOOR()->setTop(true));
		//hung from the block above, so nothing reaches the bottom half
		$this->h->set(1, 3, 0, VanillaBlocks::STONE());
		$this->h->place(1, 2, 0, VanillaBlocks::LEVER()->setFacing(LeverFacing::DOWN_AXIS_X));
		$this->h->tick();

		$this->h->get(1, 2, 0)->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0, 0, 0));
		$this->h->tick(5);
		foreach([1, 2] as $y){
			$door = $this->h->get(0, $y, 0);
			self::assertInstanceOf(Door::class, $door);
			self::assertTrue($door->isOpen(), "half at y=$y");
		}

		$this->h->get(1, 2, 0)->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0, 0, 0));
		$this->h->tick(5);
		$door = $this->h->get(0, 1, 0);
		self::assertInstanceOf(Door::class, $door);
		self::assertFalse($door->isOpen());
	}

	private function doorOpen() : bool{
		$door = $this->h->get(0, 1, 0);
		self::assertInstanceOf(Door::class, $door);
		return $door->isOpen();
	}

	public function testDoorFollowsPowerEdgesButKeepsManualState() : void{
		$this->h->set(0, 1, 0, VanillaBlocks::OAK_DOOR());
		$this->h->set(0, 2, 0, VanillaBlocks::OAK_DOOR()->setTop(true));
		$this->h->tick();

		$this->h->get(0, 1, 0)->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0, 0, 0));
		$this->h->tick();
		self::assertTrue($this->doorOpen());
		$this->h->set(-1, 1, 0, VanillaBlocks::STONE());
		$this->h->tick(5);
		self::assertTrue($this->doorOpen(), "an unrelated neighbour change doesn't close a door opened by hand");

		$this->h->get(0, 1, 0)->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0, 0, 0));
		$this->h->tick();
		self::assertFalse($this->doorOpen());
		$this->h->place(1, 1, 0, VanillaBlocks::REDSTONE());
		$this->h->tick(5);
		self::assertTrue($this->doorOpen(), "a redstone block placed beside the door opens it");

		$this->h->get(0, 1, 0)->onInteract(VanillaItems::AIR(), Facing::UP, new Vector3(0, 0, 0));
		$this->h->tick();
		$this->h->set(-1, 1, 0, VanillaBlocks::AIR());
		$this->h->tick(5);
		self::assertFalse($this->doorOpen(), "closed by hand while powered, it stays closed until the power changes");

		$this->h->breakBlock(1, 1, 0);
		$this->h->tick(5);
		self::assertFalse($this->doorOpen());
		$this->h->place(1, 1, 0, VanillaBlocks::REDSTONE());
		$this->h->tick(5);
		self::assertTrue($this->doorOpen());
		$this->h->breakBlock(1, 1, 0);
		$this->h->tick(5);
		self::assertFalse($this->doorOpen(), "losing power closes it");
		self::assertSame(0, $this->h->state()->getDataChunkCount());
	}

	public function testComparatorReadsContainerThroughSolidBlock() : void{
		$this->h->place(3, 1, 0, VanillaBlocks::REDSTONE_COMPARATOR()->setFacing(Facing::WEST));
		$this->h->set(2, 1, 0, VanillaBlocks::STONE());
		$this->h->place(1, 1, 0, VanillaBlocks::HOPPER());
		$tile = $this->h->world->getTileAt(1, 1, 0);
		self::assertInstanceOf(ContainerTile::class, $tile);
		$this->h->tick(4);

		$tile->getInventory()->setItem(0, VanillaItems::STICK()->setCount(64));
		$this->h->tick(4);
		$comparator = $this->h->get(3, 1, 0);
		self::assertInstanceOf(RedstoneComparator::class, $comparator);
		self::assertSame(3, $comparator->getOutputSignalStrength(), "one full slot of five");

		$tile->getInventory()->clearAll();
		$this->h->tick(4);
		$comparator = $this->h->get(3, 1, 0);
		self::assertInstanceOf(RedstoneComparator::class, $comparator);
		self::assertSame(0, $comparator->getOutputSignalStrength());
	}

	public function testPoweredHopperLocks() : void{
		$this->h->place(0, 1, 0, VanillaBlocks::HOPPER());
		$this->h->place(1, 1, 0, VanillaBlocks::REDSTONE());
		$this->h->tick(2);
		$hopper = $this->h->get(0, 1, 0);
		self::assertInstanceOf(Hopper::class, $hopper);
		self::assertTrue($hopper->isPowered());

		$this->h->breakBlock(1, 1, 0);
		$this->h->tick(2);
		$hopper = $this->h->get(0, 1, 0);
		self::assertInstanceOf(Hopper::class, $hopper);
		self::assertFalse($hopper->isPowered());
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

	public function testRepeaterIgnoresPerpendicularRepeaterBehindIt() : void{
		//the repeater at 1,1,0 points south, past the back of the one at 2,1,0
		$this->h->place(2, 1, 0, VanillaBlocks::REDSTONE_REPEATER()->setFacing(Facing::WEST));
		$this->h->place(1, 1, 0, VanillaBlocks::REDSTONE_REPEATER()->setFacing(Facing::NORTH));
		$this->h->place(1, 1, -1, VanillaBlocks::REDSTONE());
		$this->h->tick(20);
		$side = $this->h->get(1, 1, 0);
		self::assertInstanceOf(RedstoneRepeater::class, $side);
		self::assertTrue($side->isPowered());
		$this->h->set(2, 1, -1, VanillaBlocks::STONE()); //makes the front repeater re-read its input
		$this->h->tick(20);
		$repeater = $this->h->get(2, 1, 0);
		self::assertInstanceOf(RedstoneRepeater::class, $repeater);
		self::assertFalse($repeater->isPowered());
	}

	public function testSubtractingComparatorFollowsSideWire() : void{
		$this->h->place(2, 1, 0, VanillaBlocks::REDSTONE_COMPARATOR()->setFacing(Facing::WEST)->setSubtractMode(true));
		$this->h->place(1, 1, 0, VanillaBlocks::REDSTONE());
		$this->h->place(2, 1, 1, VanillaBlocks::REDSTONE_WIRE());
		$this->h->tick(10);
		$comparator = $this->h->get(2, 1, 0);
		self::assertInstanceOf(RedstoneComparator::class, $comparator);
		self::assertSame(15, $comparator->getOutputSignalStrength());

		//the wire reaches 15, the same level the comparator outputs, which must still be picked up
		$this->h->place(2, 1, 2, VanillaBlocks::REDSTONE());
		$this->h->tick(10);
		self::assertSame(15, $this->wire(2, 1, 1)->getOutputSignalStrength());
		$comparator = $this->h->get(2, 1, 0);
		self::assertInstanceOf(RedstoneComparator::class, $comparator);
		self::assertSame(0, $comparator->getOutputSignalStrength());
	}
}
