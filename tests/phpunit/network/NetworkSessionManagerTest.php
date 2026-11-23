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

	public function testAFailingDisconnectDoesNotSkipTheOtherSessions() : void{
		$manager = new NetworkSessionManager();
		$failing = $this->createMock(NetworkSession::class);
		$failing->method("disconnect")->willThrowException(new \RuntimeException("quit handler failure"));
		$failing->method("getLogger")->willReturn(new \PrefixedLogger($this->createMock(\Logger::class), "test"));
		$manager->add($failing);
		$next = $this->createMock(NetworkSession::class);
		$next->expects(self::once())->method("disconnect");
		$manager->add($next);

		$manager->close("shutdown");

		self::assertSame(0, $manager->getSessionCount());
	}
}
