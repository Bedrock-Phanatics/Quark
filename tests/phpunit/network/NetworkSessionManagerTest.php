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

namespace quark\network;

use PHPUnit\Framework\TestCase;
use quark\network\mcpe\NetworkSession;

final class NetworkSessionManagerTest extends TestCase{

	public function testSessionsDroppedBeforeLoginStopCounting() : void{
		$manager = new NetworkSessionManager();
		$session = $this->createMock(NetworkSession::class);
		$session->method("isConnected")->willReturn(false);
		$manager->add($session);
		$manager->tick();

		self::assertSame(0, $manager->getSessionCount());
		self::assertSame(0, $manager->getValidSessionCount());
	}

	public function testSessionDisconnectedDuringItsTickIsTickedOnceMoreToDispose() : void{
		$manager = new NetworkSessionManager();
		$session = $this->createMock(NetworkSession::class);
		$connected = true;
		$session->method("isConnected")->willReturnCallback(function() use (&$connected) : bool{ return $connected; });
		$session->expects(self::exactly(2))->method("tick")->willReturnCallback(function() use (&$connected) : void{ $connected = false; });
		$manager->add($session);

		$manager->tick();
		self::assertSame(1, $manager->getSessionCount(), "disposal happens on the next tick");
		$manager->tick();
		self::assertSame(0, $manager->getSessionCount());
		$manager->tick();
	}
}
