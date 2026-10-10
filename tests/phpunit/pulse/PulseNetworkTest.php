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

namespace quark\pulse;

use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\VarInt;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\ItemStackRequestPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\RequestNetworkSettingsPacket;
use pocketmine\network\mcpe\protocol\serializer\BitSet;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequest;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\RegisteredListener;
use quark\event\server\DataPacketDecodeEvent;
use quark\event\server\DataPacketReceiveEvent;
use quark\network\mcpe\compression\DecompressionException;
use quark\network\mcpe\compression\SnappyCompressor;
use quark\network\mcpe\compression\ZlibCompressor;
use quark\network\mcpe\handler\InGamePacketHandler;
use quark\network\mcpe\handler\PacketHandler;
use quark\network\mcpe\handler\PacketHandlerAction;
use quark\network\mcpe\handler\SessionStartPacketHandler;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\PacketRateLimiter;
use quark\network\mcpe\raklib\RakLibInterface;
use quark\network\PacketHandlingException;
use quark\plugin\Plugin;
use quark\pulse\internal\PulseCapture;
use quark\pulse\internal\PulseContext;
use quark\pulse\internal\PulseNetwork;
use quark\pulse\internal\PulseNetworkWatchdog;
use quark\pulse\internal\PulseNetworkWork;
use quark\pulse\internal\PulseZones;
use quark\utils\MainLoggerThread;
use quark\utils\Utils;
use raklib\server\ipc\UserToRakLibThreadMessageSender;
use function array_column;
use function array_fill;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function function_exists;
use function gzencode;
use function json_encode;
use function ord;
use function range;
use function str_repeat;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;
use function unpack;
use function zlib_encode;
use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;
use const ZLIB_ENCODING_DEFLATE;
use const ZLIB_ENCODING_GZIP;
use const ZLIB_ENCODING_RAW;

/**
 * @phpstan-import-type NetworkCapture from PulseNetwork
 */
final class PulseNetworkTest extends TestCase{
	/** @return NetworkCapture */
	private function network(PulseSession $session) : array{
		$network = $session->getCapture()["network"] ?? null;
		self::assertNotNull($network);
		return $network;
	}
	protected function setUp() : void{
		Pulse::reset();
		PulseZones::init();
	}

	protected function tearDown() : void{
		Pulse::reset();
	}

	private function set(object $object, string $property, mixed $value) : void{
		(new \ReflectionProperty($object instanceof NetworkSession ? NetworkSession::class : $object::class, $property))->setValue($object, $value);
	}

	private function session(?NetworkSession $session = null) : NetworkSession{
		$session ??= (new \ReflectionClass(NetworkSession::class))->newInstanceWithoutConstructor();
		$this->set($session, "logger", new \PrefixedLogger($this->createMock(\Logger::class), "private-address"));
		$this->set($session, "packetPool", new PacketPool());
		$this->set($session, "packetBatchLimiter", new PacketRateLimiter("batch", 10000, 1));
		$this->set($session, "gamePacketLimiter", new PacketRateLimiter("packet", 10000, 1));
		$this->set($session, "handlerActions", [RequestNetworkSettingsPacket::class => PacketHandlerAction::HANDLED]);
		$this->set($session, "handler", new class extends PacketHandler{
			public function handleRequestNetworkSettings(RequestNetworkSettingsPacket $packet) : bool{ return true; }
		});
		return $session;
	}

	private function packet() : string{
		$writer = new ByteBufferWriter();
		RequestNetworkSettingsPacket::create(1000)->encode($writer);
		return $writer->getData();
	}

	/** @param list<string> $packets */
	private function batch(array $packets) : string{
		$writer = new ByteBufferWriter();
		PacketBatch::encodeRaw($writer, $packets);
		return $writer->getData();
	}

	private function rejected(NetworkSession $session, string $payload) : void{
		try{
			$session->handleEncoded($payload);
			self::fail("Invalid packet accepted");
		}catch(PacketHandlingException){}
	}

	private function itemStackPrefix(int $count) : string{
		$writer = new ByteBufferWriter();
		VarInt::writeUnsignedInt($writer, ItemStackRequestPacket::NETWORK_ID);
		VarInt::writeUnsignedInt($writer, $count);
		return $writer->getData();
	}

	private function authInputPrefix(int $count) : string{
		$writer = new ByteBufferWriter();
		VarInt::writeUnsignedInt($writer, PlayerAuthInputPacket::NETWORK_ID);
		$writer->writeByteArray(str_repeat("\x00", 32));
		VarInt::writeUnsignedInt($writer, $count);
		return $writer->getData();
	}

	/** @param list<int> $flags */
	private function authInputPacket(array $flags) : string{
		$packet = PlayerAuthInputPacket::create(
			new Vector3(1, 2, 3), 4, 5, 6, 0, 0, new BitSet(PlayerAuthInputFlags::NUMBER_OF_FLAGS),
			1, 0, 0, new Vector2(0, 0), 123, new Vector3(0, 0, 0), null, null, null, null,
			0, 0, new Vector3(0, 0, 0), new Vector2(0, 0)
		);
		$packet->senderSubId = 2;
		$packet->recipientSubId = 3;
		$writer = new ByteBufferWriter();
		$packet->encode($writer);
		$buffer = $writer->getData();
		$stream = new ByteBufferReader($buffer);
		VarInt::readUnsignedInt($stream);
		$stream->setOffset($stream->getOffset() + 32);
		$prefixLength = $stream->getOffset();
		self::assertSame(0, VarInt::readUnsignedInt($stream));
		$writer->clear();
		$writer->writeByteArray(substr($buffer, 0, $prefixLength));
		VarInt::writeUnsignedInt($writer, count($flags));
		foreach($flags as $flag){ VarInt::writeSignedInt($writer, $flag); }
		$writer->writeByteArray(substr($buffer, $stream->getOffset()));
		return $writer->getData();
	}

	public function testInputFlagLimitsRunBeforeDecoding() : void{
		$session = $this->session();
		$packet = new class extends PlayerAuthInputPacket{
			protected function decodePayload(ByteBufferReader $in) : void{ TestCase::fail("Oversized flag list reached decoder"); }
		};
		$this->set($session, "handlerActions", [$packet::class => PacketHandlerAction::HANDLED, PlayerAuthInputPacket::class => PacketHandlerAction::HANDLED]);
		try{ $session->handleDataPacket($packet, $this->authInputPrefix(67)); self::fail("Limit requires Pulse"); }catch(PacketHandlingException $e){
			self::assertSame("Too many input flags in PlayerAuthInputPacket", $e->getMessage());
		}
		$capture = Pulse::start();
		Pulse::beginTick();
		foreach([67, 1000000, 0xffffffff] as $count){
			try{ $session->handleDataPacket($packet, $this->authInputPrefix($count)); self::fail("Oversized flag list accepted"); }catch(PacketHandlingException){}
		}
		$batch = $this->batch([$this->authInputPrefix(1000000) . str_repeat("\x00", 1000000)]);
		$this->set($session, "enableCompression", true);
		$this->set($session, "compressor", new ZlibCompressor(7, 0, ZlibCompressor::DEFAULT_MAX_DECOMPRESSION_SIZE));
		$this->rejected($session, "\x00" . Utils::assumeNotFalse(zlib_encode($batch, ZLIB_ENCODING_RAW)));
		Pulse::endTick();
		Pulse::stop();
		$data = $this->network($capture);
		self::assertSame(array_fill(0, 4, "packet.handler_validation"), array_column($data["events"], 4));
		self::assertSame([67, 1000000, 0xffffffff, 1000000], array_column($data["events"], 5));
		self::assertSame(array_fill(0, 4, PlayerAuthInputFlags::NUMBER_OF_FLAGS), array_column($data["events"], 6));
		self::assertSame(array_fill(0, 4, PlayerAuthInputPacket::NETWORK_ID), array_column($data["events"], 3));
		self::assertSame(array_fill(0, 4, 1), array_column($data["events"], 1));
		self::assertSame([4, 0, 4], [$data["windows"][0][3], $data["windows"][0][6], $data["windows"][0][7]]);
		PulseReport::create([$capture->getCapture()]);
	}

	public function testInputFlagBoundariesPreservePacketFields() : void{
		$session = $this->session();
		$this->set($session, "handlerActions", [PlayerAuthInputPacket::class => PacketHandlerAction::HANDLED]);
		$handler = new class extends PacketHandler{
			/** @var list<PlayerAuthInputPacket> */
			public array $packets = [];
			public function handlePlayerAuthInput(PlayerAuthInputPacket $packet) : bool{ $this->packets[] = $packet; return true; }
		};
		$this->set($session, "handler", $handler);
		$cases = [[], [0], range(0, PlayerAuthInputFlags::NUMBER_OF_FLAGS - 1)];
		foreach($cases as $flags){ $session->handleEncoded($this->batch([$this->authInputPacket($flags)])); }
		self::assertCount(3, $handler->packets);
		foreach($handler->packets as $index => $packet){
			self::assertSame([2, 3, 123, 4.0, 5.0, 6.0], [$packet->senderSubId, $packet->recipientSubId, $packet->getTick(), $packet->getPitch(), $packet->getYaw(), $packet->getHeadYaw()]);
			self::assertEquals(new Vector3(1, 2, 3), $packet->getPosition());
			$flags = [];
			for($i = 0; $i < PlayerAuthInputFlags::NUMBER_OF_FLAGS; ++$i){ if($packet->getInputFlags()->get($i)){ $flags[] = $i; } }
			self::assertSame($cases[$index], $flags);
		}
	}

	public function testMalformedInputFlagsUseControlledDisconnects() : void{
		$session = $this->getMockBuilder(NetworkSession::class)->disableOriginalConstructor()->onlyMethods(["getIp", "getDisplayName", "disconnectWithError"])->getMock();
		$session->method("getIp")->willReturn("192.0.2.1");
		$session->method("getDisplayName")->willReturn("private-name");
		$this->session($session);
		$this->set($session, "handlerActions", [PlayerAuthInputPacket::class => PacketHandlerAction::HANDLED]);
		$header = substr($this->authInputPrefix(0), 0, -1);
		$buffers = [$this->authInputPacket([-1]), $this->authInputPacket([66]), $this->authInputPacket([0x7fffffff]), $this->authInputPacket([0, 0]), substr($header, 0, -1), $header, $header . "\x80", $header . str_repeat("\x80", 6), $this->authInputPrefix(1)];
		$session->expects(self::exactly(count($buffers)))->method("disconnectWithError");
		$interface = (new \ReflectionClass(RakLibInterface::class))->newInstanceWithoutConstructor();
		$sender = $this->createMock(UserToRakLibThreadMessageSender::class);
		$sender->expects(self::exactly(count($buffers)))->method("blockAddress")->with("192.0.2.1", 5);
		$this->set($interface, "interface", $sender);
		$this->set($interface, "sessions", [9000 => $session]);
		$capture = Pulse::start();
		foreach($buffers as $buffer){ $interface->onPacketReceive(9000, "\xfe" . $this->batch([$buffer])); }
		Pulse::stop();
		$data = $this->network($capture);
		self::assertSame(array_merge(...array_fill(0, count($buffers), ["packet.malformed", "packet.bad_packet_disconnect"])), array_column($data["events"], 4));
		self::assertSame([count($buffers), 0, count($buffers)], [$data["windows"][0][3], $data["windows"][0][6], $data["windows"][0][7]]);
	}

	public function testItemStackRequestLimitRunsBeforePayloadDecoding() : void{
		$session = $this->session();
		$packet = new class extends ItemStackRequestPacket{
			protected function decodePayload(ByteBufferReader $in) : void{ TestCase::fail("Oversized collection reached decoder"); }
		};
		$this->set($session, "handlerActions", [$packet::class => PacketHandlerAction::HANDLED, ItemStackRequestPacket::class => PacketHandlerAction::HANDLED]);
		$buffer = $this->itemStackPrefix(81);
		try{ $session->handleDataPacket($packet, $buffer); self::fail("Limit requires Pulse"); }catch(PacketHandlingException $e){
			self::assertSame("Too many requests in ItemStackRequestPacket", $e->getMessage());
		}
		$capture = Pulse::start();
		Pulse::beginTick();
		foreach([81, 100000, 0xffffffff] as $count){
			try{ $session->handleDataPacket($packet, $this->itemStackPrefix($count)); self::fail("Oversized collection accepted"); }catch(PacketHandlingException){}
		}
		$request = new ByteBufferWriter();
		(new ItemStackRequest(0, [], [], 0))->write($request);
		$batch = $this->batch([$this->itemStackPrefix(100000) . str_repeat($request->getData(), 100000)]);
		$this->set($session, "enableCompression", true);
		$this->set($session, "compressor", new ZlibCompressor(7, 0, ZlibCompressor::DEFAULT_MAX_DECOMPRESSION_SIZE));
		$this->rejected($session, "\x00" . Utils::assumeNotFalse(zlib_encode($batch, ZLIB_ENCODING_RAW)));
		Pulse::endTick();
		Pulse::stop();
		$data = $this->network($capture);
		self::assertSame(array_fill(0, 4, "packet.handler_validation"), array_column($data["events"], 4));
		self::assertSame([81, 100000, 0xffffffff, 100000], array_column($data["events"], 5));
		self::assertSame(array_fill(0, 4, InGamePacketHandler::MAX_ITEM_STACK_REQUESTS), array_column($data["events"], 6));
		self::assertSame(array_fill(0, 4, "reject_batch"), array_column($data["events"], 7));
		self::assertSame(array_fill(0, 4, 1), array_column($data["events"], 1));
		self::assertSame([4, 0, 4], [$data["windows"][0][3], $data["windows"][0][6], $data["windows"][0][7]]);
		PulseReport::create([$capture->getCapture()]);
	}

	public function testItemStackRequestBoundariesAndMalformedPrefixes() : void{
		$session = $this->session();
		$this->set($session, "handlerActions", [ItemStackRequestPacket::class => PacketHandlerAction::HANDLED]);
		$handler = new class extends PacketHandler{
			public int $calls = 0;
			public function handleItemStackRequest(ItemStackRequestPacket $packet) : bool{
				++$this->calls;
				TestCase::assertSame([2, 3], [$packet->senderSubId, $packet->recipientSubId]);
				foreach($packet->getRequests() as $request){ TestCase::assertSame(["filter"], $request->getFilterStrings()); }
				return true;
			}
		};
		$this->set($session, "handler", $handler);
		$capture = Pulse::start();
		foreach([0, 1, InGamePacketHandler::MAX_ITEM_STACK_REQUESTS] as $count){
			$packet = ItemStackRequestPacket::create(array_fill(0, $count, new ItemStackRequest(7, [], ["filter"], 0)));
			$packet->senderSubId = 2;
			$packet->recipientSubId = 3;
			$writer = new ByteBufferWriter();
			$packet->encode($writer);
			$session->handleEncoded($this->batch([$writer->getData()]));
		}
		self::assertSame(3, $handler->calls);
		$header = substr($this->itemStackPrefix(0), 0, -1);
		foreach(["", $header, $header . "\x80", $header . str_repeat("\x80", 6), "\x00\x51"] as $buffer){
			try{ $session->handleDataPacket(new ItemStackRequestPacket(), $buffer); self::fail("Malformed prefix accepted"); }catch(PacketHandlingException){}
		}
		Pulse::stop();
		$data = $this->network($capture);
		self::assertSame(array_fill(0, 5, "packet.malformed"), array_column($data["events"], 4));
		self::assertSame([8, 3, 5], [$data["windows"][0][3], $data["windows"][0][6], $data["windows"][0][7]]);
	}

	public function testCollectionLimitsPreservePluginDecodeDecisions() : void{
		$session = $this->session();
		$plugin = $this->createMock(Plugin::class);
		$listener = new RegisteredListener(static function(DataPacketDecodeEvent $event) : void{
			if($event->isCancelled()){ $event->uncancel(); }else{ $event->cancel(); }
		}, EventPriority::NORMAL, $plugin, true, Pulse::zone("test.item_stack_cancel"));
		$list = HandlerListManager::global()->getListFor(DataPacketDecodeEvent::class);
		$list->register($listener);
		$capture = Pulse::start();
		try{
			foreach([ItemStackRequestPacket::class => $this->itemStackPrefix(81), PlayerAuthInputPacket::class => $this->authInputPrefix(67)] as $class => $buffer){
				$this->set($session, "handlerActions", [$class => PacketHandlerAction::HANDLED]);
				$payload = $this->batch([$buffer]);
				$session->handleEncoded($payload);
				$this->set($session, "handlerActions", []);
				$this->rejected($session, $payload);
			}
		}finally{
			$list->unregister($listener);
			Pulse::stop();
		}
		$data = $this->network($capture);
		self::assertSame(["packet.handler_validation", "packet.handler_validation"], array_column($data["events"], 4));
		self::assertSame([4, 0, 2, 2, 0], [$data["windows"][0][3], $data["windows"][0][6], $data["windows"][0][7], $data["windows"][0][8], $data["windows"][0][9]]);
	}

	public function testItemStackHandlerStillLimitsAlreadyDecodedRequests() : void{
		$handler = (new \ReflectionClass(InGamePacketHandler::class))->newInstanceWithoutConstructor();
		$packet = ItemStackRequestPacket::create(array_fill(0, InGamePacketHandler::MAX_ITEM_STACK_REQUESTS + 1, new ItemStackRequest(0, [], [], 0)));
		$this->expectException(PacketHandlingException::class);
		$handler->handleItemStackRequest($packet);
	}

	public function testActivePacketStagesRestoreNestedCallsAndClearFailures() : void{
		$file = sys_get_temp_dir() . "/" . uniqid("pulse-packet-work-", true);
		$watchdog = new PulseNetworkWatchdog(new MainLoggerThread($file, null), 1);
		Pulse::start();
		$network = Pulse::getNetworkTelemetry();
		self::assertNotNull($network);
		$network->watch($watchdog);
		$session = $this->session();
		$nested = $this->session();
		$session->recordProtocolVersion(1000);
		$payload = $this->batch([$this->packet()]);
		$stages = [];
		$assertStage = static function(string $stage) use ($watchdog, &$stages) : void{
			$record = $watchdog->record;
			self::assertSame($stage, PulseNetworkWork::STAGES[ord($record[-1])]);
			$data = unpack("Jsequence/Jstarted/Joffset/Jtick/Jprotocol/Nsession/Npacket", $record);
			self::assertIsArray($data);
			self::assertSame([1, 1000, 1, 193], [$data["tick"], $data["protocol"], $data["session"], $data["packet"]]);
			$stages[] = $stage;
		};
		$handler = new class($assertStage, $nested, $payload, $watchdog) extends PacketHandler{
			/** @param \Closure(string) : void $assertStage */
			public function __construct(private \Closure $assertStage, private NetworkSession $nested, private string $payload, private PulseNetworkWatchdog $watchdog){}
			public function handleRequestNetworkSettings(RequestNetworkSettingsPacket $packet) : bool{
				($this->assertStage)("handle");
				$parent = $this->watchdog->record;
				$this->nested->handleEncoded($this->payload);
				TestCase::assertSame($parent, $this->watchdog->record);
				return true;
			}
		};
		$this->set($session, "handler", $handler);
		$plugin = $this->createMock(Plugin::class);
		$listeners = [];
		foreach([DataPacketDecodeEvent::class => "decode_event", DataPacketReceiveEvent::class => "receive_event"] as $eventClass => $stage){
			$listener = new RegisteredListener(static function(DataPacketDecodeEvent|DataPacketReceiveEvent $event) use ($session, $assertStage, $stage) : void{
				if($event->getOrigin() === $session){ $assertStage($stage); }
			}, EventPriority::NORMAL, $plugin, true, Pulse::zone("test.stage"));
			$list = HandlerListManager::global()->getListFor($eventClass);
			$list->register($listener);
			$listeners[] = [$list, $listener];
		}
		try{
			Pulse::beginTick();
			$session->handleEncoded($payload);
			self::assertSame(["decode_event", "receive_event", "handle"], $stages);
			self::assertSame("", $watchdog->record);
			$this->rejected($session, "\x80");
			self::assertSame("", $watchdog->record);
			$this->rejected($session, $this->batch(["\xc1\x01"]));
			self::assertSame("", $watchdog->record);
			$this->set($session, "handler", new class extends PacketHandler{
				public function handleRequestNetworkSettings(RequestNetworkSettingsPacket $packet) : bool{ throw new \RuntimeException("plugin failure"); }
			});
			try{ $session->handleEncoded($payload); self::fail("Handler failure ignored"); }catch(\RuntimeException $e){ self::assertSame("plugin failure", $e->getMessage()); }
			self::assertSame("", $watchdog->record);
			$packet = new class extends RequestNetworkSettingsPacket{
				protected function decodePayload(ByteBufferReader $in) : void{ throw new \InvalidArgumentException("decoder failure"); }
			};
			$this->set($session, "handlerActions", [$packet::class => PacketHandlerAction::HANDLED]);
			try{ $session->handleDataPacket($packet, $this->packet()); self::fail("Unrelated decoder failure hidden"); }catch(\InvalidArgumentException $e){ self::assertSame("decoder failure", $e->getMessage()); }
			self::assertSame("", $watchdog->record);
		}finally{
			foreach($listeners as [$list, $listener]){ $list->unregister($listener); }
			Pulse::stop();
			unlink($file);
		}
	}

	public function testCaptureChangesInsidePacketCallbacksKeepNewWorkIntact() : void{
		$file = sys_get_temp_dir() . "/" . uniqid("pulse-packet-reset-", true);
		$writer = new MainLoggerThread($file, null);
		$watchdog = new PulseNetworkWatchdog($writer, 1);
		$newWatchdog = new PulseNetworkWatchdog($writer, 2);
		Pulse::start();
		$network = Pulse::getNetworkTelemetry();
		self::assertNotNull($network);
		$network->watch($watchdog);
		$session = $this->session();
		$plugin = $this->createMock(Plugin::class);
		$newRecord = "";
		$listener = new RegisteredListener(static function(DataPacketDecodeEvent $event) use ($newWatchdog, &$newRecord) : void{
			Pulse::reset();
			Pulse::start();
			$network = Pulse::getNetworkTelemetry();
			self::assertNotNull($network);
			$network->watch($newWatchdog);
			self::assertNotNull($network->work);
			$network->work->enter(1, 1000, "login", 193, null, PulseNetworkWork::HANDLE);
			$newRecord = $newWatchdog->record;
			$event->cancel();
		}, EventPriority::NORMAL, $plugin, true, Pulse::zone("test.reset"));
		$list = HandlerListManager::global()->getListFor(DataPacketDecodeEvent::class);
		$list->register($listener);
		try{
			$session->handleEncoded($this->batch([$this->packet()]));
			self::assertSame("", $watchdog->record);
			self::assertNotSame("", $newRecord);
			self::assertSame($newRecord, $newWatchdog->record);
			Pulse::stop();
			self::assertSame("", $newWatchdog->record);
		}finally{
			$list->unregister($listener);
			Pulse::reset();
			unlink($file);
		}
	}

	public function testSessionIdsResetWithoutRetainingOldCollectors() : void{
		$first = $this->session();
		$second = $this->session();
		$payload = $this->batch([$this->packet()]);
		$session = Pulse::start();
		Pulse::beginTick();
		$first->recordProtocolVersion(1000);
		$first->handleEncoded($payload);
		$second->handleEncoded($payload);
		$first->recordNetworkSecurityEvent("packet.state", "drop_packet", 193);
		Pulse::endTick();
		$telemetry = Pulse::getNetworkTelemetry();
		self::assertNotNull($telemetry);
		$old = \WeakReference::create($telemetry);
		unset($telemetry);
		Pulse::stop();
		$capture = $session->getCapture();
		$network = $this->network($session);
		self::assertSame([1, 2], array_column($network["sessions"], 0));
		self::assertSame(1000, $network["sessions"][0][2]);
		self::assertNull($network["sessions"][1][2]);
		self::assertSame([1, 1, 193, "packet.state"], [$network["events"][0][1], $network["events"][0][2], $network["events"][0][3], $network["events"][0][4]]);
		self::assertSame([0, 1, 1, 1, strlen($payload), strlen($payload), 1, 0, 0, 0, 0], $network["windows"][0]);
		$report = PulseReport::create([$capture]);
		self::assertSame($report->getData(), PulseReport::decode($report->encode(true))->getData());
		self::assertStringNotContainsString("private-address", $report->encode());
		$newSession = Pulse::start();
		$second->handleEncoded($payload);
		Pulse::stop();
		self::assertSame([1], array_column($this->network($newSession)["sessions"], 0));
		self::assertNull($old->get());
	}

	public function testMalformedPacketsAndStateDropsHaveSeparateCounters() : void{
		$session = $this->session();
		$capture = Pulse::start();
		$this->rejected($session, $this->batch([substr($this->packet(), 0, -1)]));
		$this->set($session, "handlerActions", []);
		$session->handleEncoded($this->batch([$this->packet()]));
		Pulse::stop();
		$data = $this->network($capture);
		self::assertSame(["packet.malformed", "packet.state"], array_column($data["events"], 4));
		self::assertSame([2, 0, 1, 0, 1], [$data["windows"][0][3], $data["windows"][0][6], $data["windows"][0][7], $data["windows"][0][8], $data["windows"][0][9]]);
		self::assertNull($data["events"][0][1]);
		PulseReport::create([$capture->getCapture()]);
	}

	public function testProtocolIsRecordedBeforeCompatibilityRejection() : void{
		$session = $this->getMockBuilder(NetworkSession::class)->disableOriginalConstructor()->onlyMethods(["disconnectIncompatibleProtocol", "sendDataPacket"])->getMock();
		$session->expects(self::once())->method("disconnectIncompatibleProtocol")->with(1);
		$session->expects(self::never())->method("sendDataPacket");
		$capture = Pulse::start();
		$handler = new SessionStartPacketHandler($session, static function() : void{ self::fail("Incompatible protocol accepted"); });
		self::assertTrue($handler->handleRequestNetworkSettings(RequestNetworkSettingsPacket::create(1)));
		Pulse::stop();
		self::assertSame(1, $this->network($capture)["sessions"][0][2]);
	}

	public function testGameRateAndUnknownPacketsRetainKnownPacketIds() : void{
		$session = $this->session();
		$capture = Pulse::start();
		$limiter = new PacketRateLimiter("packet", 1, 1, PHP_INT_MAX);
		$limiter->decrement();
		$this->set($session, "gamePacketLimiter", $limiter);
		$this->rejected($session, $this->batch([$this->packet()]));
		$this->set($session, "gamePacketLimiter", new PacketRateLimiter("packet", 10000, 1));
		$this->rejected($session, $this->batch(["\xff\x07"]));
		Pulse::stop();
		$data = $this->network($capture);
		self::assertSame(["rate.packet", "packet.unknown"], array_column($data["events"], 4));
		self::assertSame([RequestNetworkSettingsPacket::NETWORK_ID, 1023], array_column($data["events"], 3));
		self::assertSame(2, $data["windows"][0][3]);
		self::assertSame(1, $data["windows"][0][7]);
	}

	public function testTransportDropAndBadPacketDisconnectActionsAreRecorded() : void{
		$session = $this->getMockBuilder(NetworkSession::class)->disableOriginalConstructor()->onlyMethods(["getIp", "getDisplayName", "disconnectWithError"])->getMock();
		$session->method("getIp")->willReturn("192.0.2.1");
		$session->method("getDisplayName")->willReturn("private-name");
		$session->expects(self::once())->method("disconnectWithError");
		$this->session($session);
		$interface = (new \ReflectionClass(RakLibInterface::class))->newInstanceWithoutConstructor();
		$sender = $this->createMock(UserToRakLibThreadMessageSender::class);
		$sender->expects(self::once())->method("blockAddress")->with("192.0.2.1", 5);
		$this->set($interface, "interface", $sender);
		$this->set($interface, "sessions", [9000 => $session]);
		$capture = Pulse::start();
		$interface->onPacketReceive(9000, "\x00");
		$interface->onPacketReceive(9000, "\xfe");
		Pulse::stop();
		$data = $this->network($capture);
		self::assertSame(["transport.header", "batch.empty", "packet.bad_packet_disconnect"], array_column($data["events"], 4));
		self::assertSame(["drop_batch", "reject_batch", "disconnect"], array_column($data["events"], 7));
		$json = $capture->getReport()->encode();
		self::assertStringNotContainsString("private-name", $json);
		self::assertStringNotContainsString("192.0.2.1", $json);
		self::assertSame(1, $data["sessions"][0][0]);
	}

	public function testClosedOverflowSessionsAndSaturatedCounters() : void{
		$network = new PulseNetwork(100);
		$network->session(1000, "closed", 101);
		for($i = 1; $i < 1024; ++$i){ $network->session(null, "login", 101); }
		self::assertSame(0, $network->session(null, "login", 101));
		$window = $network->window(0, 102);
		$network->count($window, PulseNetwork::PACKETS, PHP_INT_MAX);
		$network->count($window, PulseNetwork::PACKETS);
		$network->event(0, null, "login", "packet.state", "drop_packet", now: 102);
		$data = $network->capture(10);
		self::assertNull($data["events"][0][2]);
		self::assertNull($data["windows"][0][1]);
		self::assertSame(1, $data["sessions"][0][5]);
		self::assertSame(1, $data["counter_overflows"]);
		self::assertSame(PHP_INT_MAX, $data["windows"][0][3]);
		$context = new PulseContext();
		$context->start("main", 100);
		$context->stop(110);
		$capture = $context->capture();
		$capture["network"] = $data;
		PulseReport::create([$capture]);
	}

	public function testPluginCancellationsDoNotBecomeValidationFailures() : void{
		$plugin = $this->createMock(Plugin::class);
		$session = $this->session();
		$packet = $this->batch([$this->packet()]);
		$capture = Pulse::start();
		foreach([DataPacketDecodeEvent::class, DataPacketReceiveEvent::class] as $eventClass){
			$listener = new RegisteredListener(static function(DataPacketDecodeEvent|DataPacketReceiveEvent $event) : void{ $event->cancel(); }, EventPriority::NORMAL, $plugin, true, Pulse::zone("test.cancel"));
			$list = HandlerListManager::global()->getListFor($eventClass);
			$list->register($listener);
			try{ $session->handleEncoded($packet); }finally{ $list->unregister($listener); }
		}
		Pulse::stop();
		$data = $this->network($capture);
		self::assertSame([], $data["events"]);
		self::assertSame([2, 1, 0, 2, 0], [$data["windows"][0][3], $data["windows"][0][6], $data["windows"][0][7], $data["windows"][0][8], $data["windows"][0][9]]);
	}

	public function testRateBatchAndResourceDecisionsAreRecorded() : void{
		$session = $this->session();
		$capture = Pulse::start();
		$limiter = new PacketRateLimiter("batch", 1, 1, PHP_INT_MAX);
		$limiter->decrement();
		$this->set($session, "packetBatchLimiter", $limiter);
		$this->rejected($session, $this->batch([$this->packet()]));
		$this->set($session, "packetBatchLimiter", new PacketRateLimiter("batch", 10000, 1));
		$this->rejected($session, "");
		$this->rejected($session, "\x80");
		$payload = $this->packet();
		$this->set($session, "noisyPacketBuffer", $payload);
		$this->rejected($session, $this->batch(array_fill(0, 300, $payload)));
		$this->set($session, "enableCompression", true);
		$this->set($session, "compressor", new ZlibCompressor(7, 0, 100));
		$this->rejected($session, "\x00" . Utils::assumeNotFalse(zlib_encode(str_repeat("a", 101), ZLIB_ENCODING_RAW)));
		$this->rejected($session, "\x00\xff");
		$this->rejected($session, "\x01a");
		Pulse::stop();
		$data = $this->network($capture);
		self::assertSame(["rate.batch", "batch.empty", "batch.malformed", "batch.packet_limit", "decompression.limit", "decompression.malformed", "compression.algorithm"], array_column($data["events"], 4));
		self::assertSame([1, 0], [$data["events"][0][5], $data["events"][0][6]]);
		self::assertSame([300, 300], [$data["events"][3][5], $data["events"][3][6]]);
		self::assertSame(299, $data["windows"][0][10]);
		PulseReport::create([$capture->getCapture()]);
	}

	public function testCollectorStorageRingsCoverageAndStaleWindows() : void{
		$network = new PulseNetwork(100);
		for($i = 0; $i < PulseNetwork::MAX_SESSIONS + 10; ++$i){ $network->session(null, "session_start", 100); }
		$old = $network->window(1, 100);
		for($i = 0; $i < PulseNetwork::MAX_WINDOWS + 7; ++$i){
			$network->count($network->window(1, 100 + $i * PulseNetwork::WINDOW_NS), PulseNetwork::BATCHES);
			$network->event(1, null, "login", "packet.state", "drop_packet", now: 100 + $i);
		}
		$network->count($old, PulseNetwork::BATCHES, 99);
		$data = $network->capture((PulseNetwork::MAX_WINDOWS + 7) * PulseNetwork::WINDOW_NS);
		self::assertCount(1024, $data["sessions"]);
		self::assertCount(4096, $data["events"]);
		self::assertCount(4096, $data["windows"]);
		self::assertSame([10, 7, 7], [$data["sessions_dropped"], $data["events_dropped"], $data["windows_dropped"]]);
		self::assertSame(7, $data["events"][0][0]);
		self::assertSame(7000000000, $data["windows"][0][0]);
		self::assertSame([1], array_values(array_unique(array_column($data["windows"], 2))));
		$context = new PulseContext();
		$context->start("main", 100);
		$context->stop(100 + $data["coverage"]["to_ns"]);
		$capture = $context->capture();
		$capture["network"] = $data;
		self::assertSame(9216, (new PulseCapture($capture))->getRowCount());
		$report = PulseReport::create([$capture]);
		self::assertSame($report->getData(), PulseReport::decode($report->encode(true))->getData());
		$network->recording = false;
		$network->event(1, null, "login", "packet.state", "drop_packet");
		$network->count($network->window(1, 100), PulseNetwork::BATCHES);
		self::assertSame($data["events"], $network->capture($data["coverage"]["to_ns"])["events"]);
	}

	public function testV1RemainsReadableAndV2NetworkInputIsValidated() : void{
		$context = new PulseContext();
		$context->start("main", 100);
		$context->network()->session(1000, "login", 100);
		$context->network()->event(1, 193, "login", "packet.state", "drop_packet", now: 101);
		$context->stop(110);
		$report = PulseReport::create([$context->capture()])->getData();
		$legacy = $report;
		$legacy["version"] = 1;
		unset($legacy["threads"][0]["network"]);
		self::assertSame($legacy, PulseReport::decode(Utils::assumeNotFalse(gzencode(json_encode($legacy, JSON_THROW_ON_ERROR))))->getData());
		foreach([
			["sessions", [[1, 0, null, "login", "IP address", null]]],
			["sessions", [[2, 0, null, "login", "login", null]]],
			["events", [[0, null, 2, null, "packet.state", null, null, "drop_packet", "login"]]],
			["events", [[11, null, 1, null, "packet.state", null, null, "drop_packet", "login"]]],
			["events", [[0, null, 1, null, "exploit", null, null, "drop_packet", "login"]]],
			["windows", [[0, 1, 1, 0, 10, 20, 1, 0, 0, 0, 0]]],
			["windows", [[1, 1, 1, 0, 10, 20, 0, 0, 0, 0, 0]]],
			["coverage", []],
			["events_dropped", -1]
		] as [$key, $value]){
			$invalid = $report;
			$invalid["threads"][0]["network"][$key] = $value;
			try{ PulseReport::decode(json_encode($invalid, JSON_THROW_ON_ERROR)); self::fail("Invalid network data accepted"); }catch(\InvalidArgumentException){}
		}
	}

	public function testZlibLimitsAndMalformedDataRemainDistinctForAllWrappers() : void{
		$compressor = new ZlibCompressor(7, 0, 1024);
		foreach([ZLIB_ENCODING_RAW, ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_GZIP] as $encoding){
			$valid = Utils::assumeNotFalse(zlib_encode(str_repeat("a", 1024), $encoding));
			self::assertSame(str_repeat("a", 1024), $compressor->decompress($valid));
			foreach([1025, 1000000] as $size){
				try{ $compressor->decompress(Utils::assumeNotFalse(zlib_encode(str_repeat("a", $size), $encoding))); self::fail("Limit not enforced"); }catch(DecompressionException $e){
					self::assertSame("decompression.limit", $e->reason);
					self::assertSame(1024, $e->limit);
					self::assertGreaterThan(1024, $e->observed);
				}
			}
			try{ $compressor->decompress(substr($valid, 0, -2)); self::fail("Truncated stream accepted"); }catch(DecompressionException $e){ self::assertSame("decompression.malformed", $e->reason); }
		}
		try{ $compressor->decompress("\xff"); self::fail("Malformed stream accepted"); }catch(DecompressionException $e){ self::assertSame("decompression.malformed", $e->reason); }
		$snappy = new SnappyCompressor(0, 1024);
		try{ $snappy->decompress("\x81\x08"); self::fail("Snappy limit not enforced"); }catch(DecompressionException $e){
			self::assertSame("decompression.limit", $e->reason);
			self::assertSame(1025, $e->observed);
		}
		try{ $snappy->decompress("\x80"); self::fail("Snappy malformed header accepted"); }catch(DecompressionException $e){ self::assertSame("decompression.malformed", $e->reason); }
		try{ $snappy->decompress("\x80\x80\x80\x80\x10"); self::fail("Snappy overflowing header accepted"); }catch(DecompressionException $e){ self::assertSame("decompression.malformed", $e->reason); }
		if(function_exists("snappy_compress")){ self::assertSame("abc", $snappy->decompress($snappy->compress("abc"))); }
	}
}
