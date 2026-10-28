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
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\EncodedPacket;
use quark\network\mcpe\protocol\Packet;
use quark\network\mcpe\protocol\packet\AddActorPacket;
use quark\network\mcpe\protocol\types\PropertySyncData;
use quark\player\Player;
use quark\Server;

final class EntitySpawnPacketTest extends TestCase{

	public function testSpawnPacketIsEncodedOncePerTick() : void{
		$state = new class{
			public int $tick = 10;
			public int $built = 0;
			/** @var list<Packet|EncodedPacket> */
			public array $sent = [];
		};
		$server = $this->createMock(Server::class);
		$server->method("getTick")->willReturnCallback(fn() : int => $state->tick);

		$entity = $this->getMockBuilder(Zombie::class)->disableOriginalConstructor()->onlyMethods(["spawnPacket"])->getMock();
		$entity->method("spawnPacket")->willReturnCallback(function() use ($state) : AddActorPacket{
			++$state->built;
			return new AddActorPacket(1, 1, "minecraft:zombie", new Vector3(0, 0, 0), new Vector3(0, 0, 0), new Vector2(0, 0), 0.0, 0.0, [], [], new PropertySyncData([], []), []);
		});
		(new \ReflectionProperty(Entity::class, "server"))->setValue($entity, $server);
		(new \ReflectionProperty(Entity::class, "id"))->setValue($entity, 41);

		$session = $this->createMock(NetworkSession::class);
		$session->method("sendDataPacket")->willReturnCallback(function(Packet|EncodedPacket $packet) use ($state) : bool{
			$state->sent[] = $packet;
			return true;
		});
		$player = $this->createMock(Player::class);
		$player->method("getNetworkSession")->willReturn($session);

		//close() would despawn entities that were never spawned
		$closed = new \ReflectionProperty(Entity::class, "closed");
		$closed->setValue($entity, true);
		$closed->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));

		$send = new \ReflectionMethod(Entity::class, "sendSpawnPacket");
		$send->invoke($entity, $player);
		$send->invoke($entity, $player);
		self::assertSame(1, $state->built);
		self::assertCount(2, $state->sent);
		self::assertInstanceOf(EncodedPacket::class, $state->sent[0]);
		self::assertSame($state->sent[0], $state->sent[1]);

		++$state->tick;
		$send->invoke($entity, $player);
		self::assertSame(2, $state->built);
		self::assertCount(3, $state->sent);
		self::assertNotSame($state->sent[0], $state->sent[2]);
	}
}
