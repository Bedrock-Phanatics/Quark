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

namespace quark\player;

use PHPUnit\Framework\TestCase;
use quark\entity\Entity;
use quark\entity\Location;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\player\PlayerViewDistanceChangeEvent;
use quark\MemoryManager;
use quark\network\mcpe\NetworkSession;
use quark\plugin\Plugin;
use quark\plugin\PluginManager;
use quark\pulse\internal\PulseZones;
use quark\Server;
use quark\ServerConfigGroup;
use quark\thread\log\AttachableThreadSafeLogger;
use quark\utils\Config;
use quark\utils\Utils;
use quark\world\World;
use function min;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;
use const PHP_INT_MAX;

final class ViewDistanceTest extends TestCase{
	/** @var list<string> */
	public static array $warnings = [];

	private const SERVER_MAX = 16;
	private const LOW_MEMORY_LIMIT = 4;

	private Server $server;
	private bool $lowMemory = false;
	/** @var list<int> */
	private array $radiusPackets = [];
	/** @var list<array{int, int}> */
	private array $events = [];
	/** @var list<string> */
	private array $files = [];

	protected function setUp() : void{
		PulseZones::init();
		$this->server = $this->getMockBuilder(Server::class)->disableOriginalConstructor()->onlyMethods(["getViewDistance", "getConfigGroup"])->getMock();
		$this->server->method("getViewDistance")->willReturn(self::SERVER_MAX);
		$this->server->method("getConfigGroup")->willReturn($this->config([]));

		$memory = $this->createMock(MemoryManager::class);
		$memory->method("getViewDistance")->willReturnCallback(fn(int $distance) : int => $this->lowMemory ? min(self::LOW_MEMORY_LIMIT, $distance) : $distance);
		(new \ReflectionProperty(Server::class, "memoryManager"))->setValue($this->server, $memory);

		$plugin = self::createStub(Plugin::class);
		$plugin->method("isEnabled")->willReturn(true);
		(new PluginManager($this->createMock(Server::class), null))->registerEvent(PlayerViewDistanceChangeEvent::class, function(PlayerViewDistanceChangeEvent $event) : void{
			$this->events[] = [$event->getOldDistance(), $event->getNewDistance()];
		}, EventPriority::NORMAL, $plugin);
	}

	protected function tearDown() : void{
		HandlerListManager::global()->unregisterAll();
		foreach($this->files as $file){
			@unlink($file);
		}
	}

	/** @param array<string, mixed> $values */
	private function config(array $values) : ServerConfigGroup{
		$path = tempnam(sys_get_temp_dir(), "quark-vd-");
		self::assertNotFalse($path);
		$this->files[] = $path;
		$config = new Config($path, Config::PROPERTIES);
		foreach(Utils::stringifyKeys($values) as $key => $value){
			$config->set($key, $value);
		}
		return new ServerConfigGroup($config, $config);
	}

	private function world(int $limit) : World{
		$world = $this->createMock(World::class);
		$world->method("getViewDistanceLimit")->willReturn($limit);
		$world->method("isLoaded")->willReturn(true);
		return $world;
	}

	private function player(World $world) : Player{
		$session = $this->createMock(NetworkSession::class);
		$session->method("syncViewAreaRadius")->willReturnCallback(function(int $radius) : void{
			$this->radiusPackets[] = $radius;
		});
		$player = $this->getMockBuilder(Player::class)->disableOriginalConstructor()->onlyMethods(["sendPosition"])->getMock();
		(new \ReflectionProperty(Entity::class, "server"))->setValue($player, $this->server);
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, new Location(0, 64, 0, $world, 0, 0));
		(new \ReflectionProperty(Player::class, "networkSession"))->setValue($player, $session);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, $this->createMock(\Logger::class));
		(new \ReflectionProperty(Player::class, "nextChunkOrderRun"))->setValue($player, 5);
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		return $player;
	}

	private function moveTo(Player $player, World $world) : void{
		(new \ReflectionProperty(Entity::class, "location"))->setValue($player, new Location(0, 64, 0, $world, 0, 0));
		(new \ReflectionMethod(Player::class, "updateViewDistance"))->invoke($player);
	}

	private function nextChunkOrderRun(Player $player) : int{
		$value = (new \ReflectionProperty(Player::class, "nextChunkOrderRun"))->getValue($player);
		self::assertIsInt($value);
		return $value;
	}

	public function testUnlistedWorldUsesTheServerMaximum() : void{
		$player = $this->player($this->world(self::SERVER_MAX));
		self::assertSame(-1, $player->getViewDistance());
		$player->setViewDistance(32);
		self::assertSame(self::SERVER_MAX, $player->getViewDistance());
		self::assertSame(32, $player->getRequestedViewDistance());
		self::assertSame([self::SERVER_MAX], $this->radiusPackets);
		self::assertSame([[-1, self::SERVER_MAX]], $this->events);
	}

	public function testWorldLimitCapsTheRequestAndRequestsBelowItPass() : void{
		$player = $this->player($this->world(6));
		$player->setViewDistance(12);
		self::assertSame(6, $player->getViewDistance());
		$player->setViewDistance(4);
		self::assertSame(4, $player->getViewDistance());
		self::assertSame([6, 4], $this->radiusPackets);
	}

	public function testRequestsBelowTheMinimumAreRaised() : void{
		$player = $this->player($this->world(self::SERVER_MAX));
		foreach([-100, 0, 1] as $request){
			$player->setViewDistance($request);
			self::assertSame(2, $player->getViewDistance());
		}
		self::assertSame([2], $this->radiusPackets);
	}

	public function testHugeRequestsAreCappedWithoutOverflow() : void{
		$player = $this->player($this->world(self::SERVER_MAX));
		$player->setViewDistance(PHP_INT_MAX);
		self::assertSame(self::SERVER_MAX, $player->getViewDistance());
	}

	public function testRepeatedIdenticalRequestsDoNothing() : void{
		$player = $this->player($this->world(8));
		$player->setViewDistance(12);
		(new \ReflectionProperty(Player::class, "nextChunkOrderRun"))->setValue($player, PHP_INT_MAX);
		for($i = 0; $i < 50; ++$i){
			$player->setViewDistance(12);
			$player->setViewDistance(9); //capped to the same effective distance
		}
		self::assertSame([8], $this->radiusPackets);
		self::assertCount(1, $this->events);
		self::assertSame(PHP_INT_MAX, $this->nextChunkOrderRun($player));
	}

	public function testChangesScheduleOneChunkReorder() : void{
		$player = $this->player($this->world(self::SERVER_MAX));
		$player->setViewDistance(8);
		self::assertSame(0, $this->nextChunkOrderRun($player));
	}

	public function testWorldTransfersApplyAndRestoreTheRequest() : void{
		$lobby = $this->world(6);
		$survival = $this->world(self::SERVER_MAX);
		$player = $this->player($survival);
		$player->setViewDistance(12);
		self::assertSame(12, $player->getViewDistance());

		$this->moveTo($player, $lobby);
		self::assertSame(6, $player->getViewDistance());
		self::assertSame(12, $player->getRequestedViewDistance());

		$this->moveTo($player, $survival);
		self::assertSame(12, $player->getViewDistance());
		self::assertSame([12, 6, 12], $this->radiusPackets);
		self::assertSame([[-1, 12], [12, 6], [6, 12]], $this->events);
	}

	public function testTransferBetweenWorldsWithTheSameEffectiveLimitSendsNothing() : void{
		$a = $this->world(8);
		$b = $this->world(10);
		$player = $this->player($a);
		$player->setViewDistance(6);
		$this->moveTo($player, $b);
		$this->moveTo($player, $a);
		self::assertSame([6], $this->radiusPackets);
	}

	public function testTransferBeforeTheClientRequestedAnythingIsIgnored() : void{
		$player = $this->player($this->world(8));
		$this->moveTo($player, $this->world(4));
		self::assertSame(-1, $player->getViewDistance());
		self::assertSame([], $this->radiusPackets);
		$player->setViewDistance(16);
		self::assertSame(4, $player->getViewDistance());
	}

	public function testRapidWorldSwitchingEndsOnTheCurrentWorldsLimit() : void{
		$worlds = [$this->world(4), $this->world(12), $this->world(6), $this->world(self::SERVER_MAX)];
		$player = $this->player($worlds[3]);
		$player->setViewDistance(10);
		for($i = 0; $i < 40; ++$i){
			$this->moveTo($player, $worlds[$i % 4]);
		}
		self::assertSame(10, $player->getViewDistance()); //last world is $worlds[3]
		$this->moveTo($player, $worlds[2]);
		self::assertSame(6, $player->getViewDistance());
	}

	public function testLowMemoryLimitAppliesAndRecovers() : void{
		$player = $this->player($this->world(self::SERVER_MAX));
		$player->setViewDistance(12);
		$this->lowMemory = true;
		(new \ReflectionMethod(Player::class, "updateViewDistance"))->invoke($player);
		self::assertSame(self::LOW_MEMORY_LIMIT, $player->getViewDistance());
		self::assertSame(12, $player->getRequestedViewDistance());

		$this->lowMemory = false;
		(new \ReflectionMethod(Player::class, "updateViewDistance"))->invoke($player);
		self::assertSame(12, $player->getViewDistance());
	}

	public function testLowMemoryAppliesOnTopOfAWorldLimit() : void{
		$this->lowMemory = true;
		$player = $this->player($this->world(3));
		$player->setViewDistance(12);
		self::assertSame(3, $player->getViewDistance());
	}

	public function testWorldLimitsComeFromTheViewDistancesTable() : void{
		$server = $this->getMockBuilder(Server::class)->disableOriginalConstructor()->onlyMethods(["getViewDistance", "getConfigGroup"])->getMock();
		$server->method("getViewDistance")->willReturn(self::SERVER_MAX);
		self::$warnings = [];
		(new \ReflectionProperty(Server::class, "logger"))->setValue($server, new class extends AttachableThreadSafeLogger{
			public function emergency($message){}
			public function alert($message){}
			public function critical($message){}
			public function error($message){}
			public function warning($message){
				ViewDistanceTest::$warnings[] = (string) $message;
			}
			public function notice($message){}
			public function info($message){}
			public function debug($message){}
			public function log($level, $message){}
			public function logException(\Throwable $e, $trace = null){}
		});
		(new \ReflectionProperty(Server::class, "configGroup"))->setValue($server, $this->config(["view-distances" => ["nether" => 8, "my.world" => 5, "huge" => 64, "tiny" => 1]]));

		self::assertSame(8, $server->getWorldViewDistanceLimit("nether"));
		self::assertSame(5, $server->getWorldViewDistanceLimit("my.world"));
		self::assertSame(self::SERVER_MAX, $server->getWorldViewDistanceLimit("huge"));
		self::assertSame(2, $server->getWorldViewDistanceLimit("tiny"));
		self::assertSame(self::SERVER_MAX, $server->getWorldViewDistanceLimit("world"));
		self::assertCount(1, self::$warnings);
		self::assertStringContainsString("view-distances.huge", self::$warnings[0]);
	}

	public function testServerAllowedDistanceWithoutAWorldUsesTheServerMaximum() : void{
		self::assertSame(self::SERVER_MAX, $this->server->getAllowedViewDistance(100));
		self::assertSame(7, $this->server->getAllowedViewDistance(7));
		self::assertSame(5, $this->server->getAllowedViewDistance(100, $this->world(5)));
		$this->lowMemory = true;
		self::assertSame(self::LOW_MEMORY_LIMIT, $this->server->getAllowedViewDistance(100));
	}
}
