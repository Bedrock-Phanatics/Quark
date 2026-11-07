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

namespace quark\network\mcpe;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\VarInt;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\ContainerClosePacket;
use pocketmine\network\mcpe\protocol\ContainerOpenPacket;
use pocketmine\network\mcpe\protocol\RequestChunkRadiusPacket;
use pocketmine\network\mcpe\protocol\SetTimePacket;
use pocketmine\network\mcpe\protocol\types\command\raw\CommandRawData;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerIds;
use pocketmine\network\mcpe\protocol\types\inventory\WindowTypes;
use quark\command\Command;
use quark\command\CommandArgs;
use quark\command\CommandSender;
use quark\command\SimpleCommandMap;
use quark\command\TestCommandSender;
use quark\lang\Language;
use quark\network\mcpe\protocol\EncodedPacket;
use quark\network\PacketHandlingException;
use quark\permission\DefaultPermissions;
use quark\permission\Permission;
use quark\permission\PermissionManager;
use quark\player\Player;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;
use quark\Server;
use quark\utils\ObjectSet;
use Vow\CancelledException;
use Vow\Deferred;
use function array_map;
use function strlen;

final class NetworkSessionTest extends TestCase{

	protected function setUp() : void{
		Pulse::reset();
		PulseZones::init();
	}

	protected function tearDown() : void{
		Pulse::reset();
	}

	private function set(NetworkSession $session, string $property, mixed $value) : void{
		new \ReflectionProperty(NetworkSession::class, $property)->setValue($session, $value);
	}

	/**
	 * @phpstan-param \Closure(NetworkSession, string) : void $onSend
	 */
	private function session(\Closure $onSend) : NetworkSession{
		$session = new \ReflectionClass(NetworkSession::class)->newInstanceWithoutConstructor();
		$language = $this->createMock(Language::class);
		$language->method("translate")->willReturn("");
		$server = $this->createMock(Server::class);
		$server->method("getLanguage")->willReturn($language);
		$this->set($session, "server", $server);
		$this->set($session, "logger", new \PrefixedLogger($this->createMock(\Logger::class), "test"));
		$this->set($session, "compressedQueue", new \SplQueue());
		$this->set($session, "disposeHooks", new ObjectSet());
		$this->set($session, "loggedIn", true);
		$this->set($session, "packetBatchLimiter", new PacketRateLimiter("Packet Batches", 2, 100));
		$this->set($session, "gamePacketLimiter", new PacketRateLimiter("Game Packets", 10000, 1));
		$this->set($session, "sender", new class($session, $onSend) implements NativeBatchPacketSender{
			/** @phpstan-param \Closure(NetworkSession, string) : void $onSend */
			public function __construct(private NetworkSession $session, private \Closure $onSend){}

			public function send(string $payload, bool $immediate, ?int $receiptId) : void{
				$this->sendBatch($payload, 0);
			}

			public function sendBatch(string $batch, int $offset) : void{
				($this->onSend)($this->session, $batch);
			}

			public function acceptLogin(string $clientPublicKeyDer) : void{}

			public function close(string $reason = "unknown reason") : void{}
		});
		return $session;
	}

	/**
	 * @return \Generator<string, array{\Closure(NetworkSession) : mixed}>
	 */
	public static function flushProvider() : \Generator{
		yield "flush while connected" => [static fn(NetworkSession $session) => $session->sendDataPacket(SetTimePacket::create(1), true)];
		yield "final flush of a disconnect" => [static fn(NetworkSession $session) => $session->disconnect("test")];
	}

	/**
	 * @dataProvider flushProvider
	 * @phpstan-param \Closure(NetworkSession) : mixed $flush
	 */
	public function testSendFailureDuringFlushDisconnectsOnce(\Closure $flush) : void{
		$sends = 0;
		$session = $this->session(static function(NetworkSession $session) use (&$sends) : void{
			$sends++;
			$session->disconnectWithError("Network send queue is full");
		});
		$disposed = 0;
		/** @phpstan-var ObjectSet<\Closure() : void> $hooks */
		$hooks = new \ReflectionProperty(NetworkSession::class, "disposeHooks")->getValue($session);
		$hooks->add(static function() use (&$disposed) : void{
			$disposed++;
		});

		$flush($session);

		self::assertFalse($session->isConnected());
		self::assertLessThanOrEqual(2, $sends);
		self::assertSame(1, $disposed);
	}

	public function testOfflineDataLoadedAfterDisconnectIsIgnored() : void{
		$sends = 0;
		$session = $this->session(static function() use (&$sends) : void{
			$sends++;
		});
		$session->disconnect("test");
		$sentBefore = $sends;

		new \ReflectionMethod(NetworkSession::class, "onOfflinePlayerDataLoaded")->invoke(
			$session,
			CompoundTag::create(),
			true,
			static fn() : bool => self::fail("The XUID check ran for a closed session"),
			"key"
		);

		self::assertSame($sentBefore, $sends);
		self::assertNull(new \ReflectionProperty(NetworkSession::class, "cachedOfflinePlayerData")->getValue($session));
	}

	private static function chunkRadius(int $radius) : string{
		return EncodedPacket::encode(RequestChunkRadiusPacket::create($radius, 32));
	}

	public function testNativelySplitPacketsSkipBatchLimits() : void{
		$session = $this->session(static function() : void{});
		$packets = [];
		for($i = 0; $i < 400; ++$i){
			$packets[] = self::chunkRadius($i);
		}
		$session->handleDecodedPackets($packets);
		for($i = 0; $i < 250; ++$i){
			$session->handleDecodedPackets([self::chunkRadius($i)]);
		}
		self::assertTrue($session->isConnected());
	}

	public function testBatchesStillHaveAPacketLimit() : void{
		$batch = "";
		for($i = 0; $i < 400; ++$i){
			$packet = self::chunkRadius($i);
			$batch .= VarInt::packUnsignedInt(strlen($packet)) . $packet;
		}
		$this->expectException(PacketHandlingException::class);
		$this->session(static function() : void{})->handleEncoded($batch);
	}

	public function testQueuedBatchesWaitForPendingCompressionInOrder() : void{
		/** @var \ArrayObject<int, string> $sent */
		$sent = new \ArrayObject();
		$session = $this->session(static function(NetworkSession $session, string $batch) use ($sent) : void{ $sent[] = $batch; });
		/** @var Deferred<string> $first */
		$first = new Deferred();
		$session->queueCompressed($first->promise);
		$session->queueCompressed("second");
		self::assertCount(0, $sent);

		$first->resolve("first");
		self::assertSame(["first", "second"], $sent->getArrayCopy());
	}

	public function testRejectedBatchDisconnectsWithoutSkippingAhead() : void{
		$sent = [];
		$session = $this->session(static function(NetworkSession $session, string $batch) use (&$sent) : void{ $sent[] = $batch; });
		/** @var Deferred<string> $first */
		$first = new Deferred();
		$session->queueCompressed($first->promise);
		$session->queueCompressed("second");

		$first->reject(new \RuntimeException("compression failed"));
		self::assertFalse($session->isConnected());
		self::assertNotContains("second", $sent);
	}

	public function testDisconnectCancelsReceiptsAndDropsLateBatches() : void{
		$sent = [];
		$session = $this->session(static function(NetworkSession $session, string $batch) use (&$sent) : void{ $sent[] = $batch; });
		/** @var Deferred<string> $pending */
		$pending = new Deferred();
		$session->queueCompressed($pending->promise);
		$receipt = $session->sendDataPacketWithReceipt(SetTimePacket::create(1), true);
		self::assertTrue($receipt->isPending());

		$session->disconnect("test");
		self::assertInstanceOf(CancelledException::class, $receipt->reason());
		self::assertInstanceOf(CancelledException::class, $session->sendDataPacketWithReceipt(SetTimePacket::create(2))->reason());

		$pending->resolve("late");
		self::assertSame([], $sent);
	}

	public function testReceiptResolvesOnceQueued() : void{
		$session = $this->session(static function() : void{});
		$receipt = $session->sendDataPacketWithReceipt(SetTimePacket::create(1), true);
		self::assertTrue($receipt->isFulfilled());
	}

	public function testSyncAvailableCommandsSendsTheCurrentMapForThisPlayer() : void{
		DefaultPermissions::registerCorePermissions();
		if(PermissionManager::getInstance()->getPermission("test.root") === null){
			PermissionManager::getInstance()->addPermission(new Permission("test.root"));
		}
		$server = self::createStub(Server::class);
		$map = new SimpleCommandMap($server);
		$server->method("getCommandMap")->willReturn($map);
		$command = new class("eco") extends Command{
			protected function run(CommandSender $sender, CommandArgs $args) : void{}
		};
		$command->setPermission("test.root");
		$command->syntax("balance");
		$map->register("test", $command);

		$session = $this->session(static function() : void{});
		$this->set($session, "server", $server);
		try{
			$session->syncAvailableCommands();
			self::fail("syncing without a player should fail");
		}catch(\LogicException){
			$this->addToAssertionCount(1);
		}

		$player = TestCommandSender::closedPlayer(self::createStub(Player::class));
		$player->method("getLanguage")->willReturn(TestCommandSender::language());
		$player->method("hasPermission")->willReturnCallback(fn(Permission|string $name) : bool => $name === "test.root");
		$this->set($session, "player", $player);
		$session->syncAvailableCommands();

		/** @phpstan-var list<string> $buffer */
		$buffer = new \ReflectionProperty(NetworkSession::class, "sendBuffer")->getValue($session);
		$packet = new AvailableCommandsPacket();
		$packet->decode(new ByteBufferReader($buffer[0]));
		self::assertSame(["eco"], array_map(fn(CommandRawData $data) => $data->getName(), $packet->commandData));
		self::assertSame(["balance"], $packet->enumValues);
	}

	public function testNegativeWindowTypesEncode() : void{
		$batch = NetworkSession::encodeBatch([
			EncodedPacket::encode(ContainerOpenPacket::entityInv(1, WindowTypes::INVENTORY, 1)),
			EncodedPacket::encode(ContainerClosePacket::create(ContainerIds::NONE, WindowTypes::NONE, true)),
		]);
		self::assertStringContainsString("\x01\xff", $batch);
		self::assertStringContainsString("\xff\xf7", $batch);
	}
}
