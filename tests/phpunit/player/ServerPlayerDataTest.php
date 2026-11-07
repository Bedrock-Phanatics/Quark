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
use pocketmine\nbt\tag\CompoundTag;
use quark\entity\Skin;
use quark\item\VanillaItems;
use quark\lang\Language;
use quark\network\mcpe\NetworkSession;
use quark\network\Network;
use quark\network\NetworkSessionManager;
use quark\pulse\internal\PulseZones;
use quark\Server;
use quark\ServerConfigGroup;
use quark\thread\log\AttachableThreadSafeLogger;
use quark\utils\Config;
use quark\utils\Filesystem;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Filesystem\Path;
use Vow\Deferred;
use Vow\Promise;
use function bin2hex;
use function mkdir;
use function random_bytes;
use function str_repeat;
use function sys_get_temp_dir;

final class ServerPlayerDataTest extends TestCase{

	private ManualPlayerDataProvider $provider;
	private Server $server;
	private Network $network;
	private string $path;

	public function setUp() : void{
		PulseZones::init();
		$this->path = Path::join(sys_get_temp_dir(), "quark-playerdata-" . bin2hex(random_bytes(6)));
		mkdir($this->path);
		$this->provider = new ManualPlayerDataProvider();

		$reflection = new \ReflectionClass(Server::class);
		$this->server = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty("playerDataProvider")->setValue($this->server, $this->provider);
		$reflection->getProperty("logger")->setValue($this->server, new class extends AttachableThreadSafeLogger{
			public function emergency($message){}
			public function alert($message){}
			public function critical($message){}
			public function error($message){}
			public function warning($message){}
			public function notice($message){}
			public function info($message){}
			public function debug($message){}
			public function log($level, $message){}
			public function logException(\Throwable $e, $trace = null){}
		});
		$config = new Config(Path::join($this->path, "config.json"), Config::JSON);
		$reflection->getProperty("configGroup")->setValue($this->server, new ServerConfigGroup($config, $config));
		$reflection->getProperty("language")->setValue($this->server, self::createStub(Language::class));

		$networkReflection = new \ReflectionClass(Network::class);
		$this->network = $networkReflection->newInstanceWithoutConstructor();
		$networkReflection->getProperty("sessionManager")->setValue($this->network, new NetworkSessionManager());
		$reflection->getProperty("network")->setValue($this->server, $this->network);
	}

	public function tearDown() : void{
		Filesystem::recursiveUnlink($this->path);
	}

	/**
	 * @return array<string, Promise<mixed>>
	 */
	private function tails() : array{
		/** @var array<string, Promise<mixed>> $tails */
		$tails = new \ReflectionProperty(Server::class, "playerDataTails")->getValue($this->server);
		return $tails;
	}

	public function testOperationsForOnePlayerRunInCallOrder() : void{
		$this->server->getOfflinePlayerData("Steve")->observe(null);
		$this->server->hasOfflinePlayerData("steve")->observe(null);
		self::assertSame(["load:Steve"], $this->provider->calls);

		$this->provider->settle("load:Steve");
		self::assertSame(["load:Steve", "has:steve"], $this->provider->calls);

		$this->provider->settle("has:steve", true);
		self::assertSame([], $this->tails());
	}

	public function testRejoinLoadWaitsForPendingSave() : void{
		/** @var \ArrayObject<int, ?CompoundTag> $loaded */
		$loaded = new \ArrayObject();
		$this->server->saveOfflinePlayerData("Steve", CompoundTag::create());
		$this->server->getOfflinePlayerData("steve")->observe(function(?CompoundTag $data) use ($loaded) : void{ $loaded[] = $data; });
		self::assertSame(["save:Steve"], $this->provider->calls);
		self::assertCount(0, $loaded);

		$this->provider->settle("save:Steve");
		self::assertSame(["save:Steve", "load:steve"], $this->provider->calls);

		$data = CompoundTag::create()->setString("rank", "admin");
		$this->provider->settle("load:steve", $data);
		self::assertCount(1, $loaded);
		self::assertSame($data, $loaded[0]);
		self::assertSame([], $this->tails());
	}

	public function testDifferentPlayersDoNotWaitForEachOther() : void{
		$this->server->getOfflinePlayerData("Steve")->observe(null);
		$this->server->getOfflinePlayerData("Alex")->observe(null);
		self::assertSame(["load:Steve", "load:Alex"], $this->provider->calls);
	}

	public function testFailedSaveDoesNotBlockLaterOperations() : void{
		$saved = $this->server->saveOfflinePlayerData("Steve", CompoundTag::create());
		$this->server->getOfflinePlayerData("Steve")->observe(null);

		$this->provider->fail("save:Steve", new PlayerDataSaveException("disk full"));
		self::assertTrue($saved->isFulfilled());
		self::assertSame(["save:Steve", "load:Steve"], $this->provider->calls);
	}

	public function testUnreadableDataLoadsAsNull() : void{
		$result = $this->server->getOfflinePlayerData("Steve");
		$this->provider->fail("load:Steve", new PlayerDataLoadException("corrupt"));
		self::assertTrue($result->isFulfilled());
		self::assertNull($result->value());
		self::assertSame([], $this->tails());
	}

	public function testUnexpectedLoadFailureRejects() : void{
		$result = $this->server->getOfflinePlayerData("Steve");
		$this->provider->fail("load:Steve", new \RuntimeException("connection lost"));
		self::assertTrue($result->isRejected());
		$result->observe(null, static fn() => null);
		self::assertSame([], $this->tails());
	}

	public function testSynchronousProviderFailuresAreContained() : void{
		$this->provider->throws = new \RuntimeException("driver crashed");

		$saved = $this->server->saveOfflinePlayerData("Steve", CompoundTag::create());
		self::assertTrue($saved->isFulfilled());

		$loaded = $this->server->getOfflinePlayerData("Steve");
		self::assertTrue($loaded->isRejected());
		$loaded->observe(null, static fn() => null);
		self::assertSame([], $this->tails());
	}

	public function testOfflinePlayerWrapsLoadedData() : void{
		$result = $this->server->getOfflinePlayer("Steve");
		$this->provider->settle("load:steve", CompoundTag::create()->setLong(Player::TAG_FIRST_PLAYED, 1700000000000));
		$player = $result->value();
		self::assertInstanceOf(OfflinePlayer::class, $player);
		self::assertSame("steve", $player->getName());
		self::assertNotNull($player->getFirstPlayed());
	}

	public function testUpdateSavesInventoryChangesAndKeepsOtherData() : void{
		$result = $this->server->updateOfflinePlayer("Steve", function(OfflinePlayer $player) : void{
			$player->getInventory()->addItem(VanillaItems::DIAMOND()->setCount(3));
		});
		$this->provider->settle("load:steve", CompoundTag::create()->setLong(Player::TAG_FIRST_PLAYED, 1700000000000));
		self::assertSame(["load:steve", "save:steve"], $this->provider->calls);

		$saved = $this->provider->saved["steve"];
		self::assertSame(1700000000000, $saved->getLong(Player::TAG_FIRST_PLAYED));
		self::assertTrue(VanillaItems::DIAMOND()->setCount(3)->equalsExact(new OfflinePlayer("steve", $saved)->getInventory()->getItem(0)));

		self::assertTrue($result->isPending());
		$this->provider->settle("save:steve");
		self::assertTrue($result->value());
		self::assertSame([], $this->tails());
	}

	public function testLoginDuringUpdateLoadsTheUpdatedData() : void{
		$this->server->updateOfflinePlayer("Steve", fn(OfflinePlayer $player) => $player->getEnderInventory()->setItem(0, VanillaItems::APPLE()))->observe(null);
		$this->server->getOfflinePlayerData("Steve")->observe(null);

		$this->provider->settle("load:steve", CompoundTag::create());
		self::assertSame(["load:steve", "save:steve"], $this->provider->calls);
		$this->provider->settle("save:steve");
		self::assertSame(["load:steve", "save:steve", "load:Steve"], $this->provider->calls);
	}

	public function testUpdateSkipsPlayersWithoutData() : void{
		$called = false;
		$result = $this->server->updateOfflinePlayer("Steve", function() use (&$called) : void{ $called = true; });
		$this->provider->settle("load:steve");

		self::assertFalse($result->value());
		self::assertFalse($called);
		self::assertSame(["load:steve"], $this->provider->calls);
	}

	public function testUpdateRefusesConnectingPlayers() : void{
		$session = self::createStub(NetworkSession::class);
		$session->method("getPlayerInfo")->willReturn(new PlayerInfo("STEVE", Uuid::uuid4(), new Skin("test", str_repeat("\0", 64 * 32 * 4)), "en_US"));
		$this->network->getSessionManager()->add($session);

		$result = $this->server->updateOfflinePlayer("steve", fn() => null);

		self::assertFalse($result->value());
		self::assertSame([], $this->provider->calls);
	}

	public function testUpdateFailureRejectsAndReleasesQueue() : void{
		$result = $this->server->updateOfflinePlayer("Steve", fn() => throw new \RuntimeException("plugin bug"));
		$this->provider->settle("load:steve", CompoundTag::create());

		self::assertTrue($result->isRejected());
		$result->observe(null, static fn() => null);
		self::assertSame([], $this->tails());
	}
}

final class ManualPlayerDataProvider implements PlayerDataProvider{
	/** @var list<string> */
	public array $calls = [];
	public ?\Throwable $throws = null;
	/** @var array<string, CompoundTag> */
	public array $saved = [];
	/** @var array<string, Deferred<mixed>> */
	private array $pending = [];

	/**
	 * @return Promise<never>
	 */
	private function begin(string $operation, string $name) : Promise{
		if($this->throws !== null){
			throw $this->throws;
		}
		$key = $operation . ":" . $name;
		$this->calls[] = $key;
		$this->pending[$key] = $deferred = new Deferred();
		return $deferred->promise; //@phpstan-ignore return.type
	}

	public function settle(string $key, mixed $value = null) : void{
		($this->pending[$key] ?? throw new \LogicException("$key was never started"))->resolve($value);
	}

	public function fail(string $key, \Throwable $reason) : void{
		($this->pending[$key] ?? throw new \LogicException("$key was never started"))->reject($reason);
	}

	public function hasData(string $name) : Promise{
		return $this->begin("has", $name);
	}

	public function loadData(string $name) : Promise{
		return $this->begin("load", $name);
	}

	public function saveData(string $name, CompoundTag $data) : Promise{
		$this->saved[$name] = $data;
		return $this->begin("save", $name);
	}
}
