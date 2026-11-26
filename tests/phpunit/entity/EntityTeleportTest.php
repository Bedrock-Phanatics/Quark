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

namespace quark\entity;

use PHPUnit\Framework\TestCase;
use quark\command\TestCommandSender;
use quark\entity\object\ItemEntity;
use quark\item\VanillaItems;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\NetworkSession;
use quark\player\Player;
use quark\world\Position;
use quark\world\redstone\RedstoneHarness;
use quark\world\World;
use function spl_object_id;

final class EntityTeleportTest extends TestCase{
	public function testTeleportToAnUnloadedWorldLeavesTheEntityInPlace() : void{
		$from = (new RedstoneHarness(0))->world;
		$to = $this->createMock(World::class);
		$to->method("isLoaded")->willReturnOnConsecutiveCalls(true, false);
		$destination = new Position(10, 1, 10, $to);
		$entity = new ItemEntity(new Location(0.5, 1, 0.5, $from, 0, 0), VanillaItems::STICK());

		self::assertFalse($entity->teleport($destination));
		self::assertSame($from, $entity->getWorld());
		self::assertSame(0.5, $entity->getPosition()->x);
	}

	public function testEntityTeleportedToAnotherWorldIsSpawnedToItsViewers() : void{
		$from = (new RedstoneHarness(0))->world;
		$to = (new RedstoneHarness(0))->world;
		$viewer = TestCommandSender::closedPlayer($this->createMock(Player::class));
		$viewer->method("getWorld")->willReturn($to);
		$viewer->method("hasReceivedChunk")->willReturn(true);
		$session = $this->createMock(NetworkSession::class);
		$session->method("getTypeConverter")->willReturn(TypeConverter::getInstance());
		$viewer->method("getNetworkSession")->willReturn($session);
		(new \ReflectionProperty(World::class, "playerChunkListeners"))->setValue($to, [World::chunkHash(0, 0) => [spl_object_id($viewer) => $viewer]]);
		$entity = new ItemEntity(new Location(0.5, 1, 0.5, $from, 0, 0), VanillaItems::STICK());
		$entity->spawnToAll();

		self::assertTrue($entity->teleport(new Position(0.5, 1, 0.5, $to)));

		self::assertSame($to, $entity->getWorld());
		//keyed checks, since a failing assertSame() would export the whole mocked player
		self::assertCount(1, $entity->getViewers());
		self::assertArrayHasKey(spl_object_id($viewer), $entity->getViewers());
	}
}
