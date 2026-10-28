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

use Bedwire\PacketId;
use Bedwire\Protocol;
use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\VarInt;
use pocketmine\math\Vector3;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\RegisteredListener;
use quark\event\server\DataPacketDecodeEvent;
use quark\event\server\DataPacketReceiveEvent;
use quark\network\AdvancedNetworkInterface;
use quark\network\mcpe\compression\Compressor;
use quark\network\mcpe\compression\DecompressionException;
use quark\network\mcpe\compression\SnappyCompressor;
use quark\network\mcpe\compression\ZlibCompressor;
use quark\network\mcpe\encryption\DecryptionException;
use quark\network\mcpe\encryption\EncryptionContext;
use quark\network\mcpe\handler\InGamePacketHandler;
use quark\network\mcpe\handler\PacketHandler;
use quark\network\mcpe\handler\PacketHandlerAction;
use quark\network\mcpe\handler\PacketHandlerInspector;
use quark\network\mcpe\handler\SessionStartPacketHandler;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\PacketRateLimiter;
use quark\network\mcpe\protocol\packet\InventoryTransactionPacket;
use quark\network\mcpe\protocol\packet\ItemStackRequestPacket;
use quark\network\mcpe\protocol\packet\PlayerAuthInputPacket;
use quark\network\mcpe\protocol\packet\RequestNetworkSettingsPacket;
use quark\network\mcpe\protocol\Packets;
use quark\network\mcpe\protocol\RecordedPackets;
use quark\network\mcpe\protocol\types\CompressionAlgorithm;
use quark\network\mcpe\protocol\types\ItemStackRequestPacketData;
use quark\network\mcpe\protocol\types\PlayerAuthInputFlags;
use quark\network\mcpe\raklib\RakLibInterface;
use quark\network\Network;
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
use function str_replace;
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
		$this->set($session, "packetBatchLimiter", new PacketRateLimiter("batch", 10000, 1));
		$this->set($session, "gamePacketLimiter", new PacketRateLimiter("packet", 10000, 1));
		$this->handler($session, new class extends PacketHandler{
			public function handleRequestNetworkSettings(RequestNetworkSettingsPacket $packet) : bool{ return true; }
		});
		return $session;
	}

	/**
	 * Attaches a handler the way NetworkSession::setHandler() does, without changing the session's phase.
	 */
	private function handler(NetworkSession $session, PacketHandler $handler) : void{
		[$actions, $methods] = PacketHandlerInspector::inspect($handler);
		$closures = [];
		foreach($methods as $id => $method){
			$closures[$id] = (new \ReflectionMethod($handler, $method))->getClosure($handler);
		}
		$this->set($session, "handler", $handler);
		$this->set($session, "handlerActions", $actions);
		$this->set($session, "handlerMethods", $closures);
	}

	/**
	 * Attaches the gameplay handler without a player, which is enough for packets it rejects before touching game state.
	 */
	private function inGame(NetworkSession $session) : void{
		$this->handler($session, (new \ReflectionClass(InGamePacketHandler::class))->newInstanceWithoutConstructor());
	}

	/**
	 * Marks packets as handled, so they are decoded whatever the current handler implements.
	 *
	 * @param list<int> $ids
	 */
	private function actions(NetworkSession $session, array $ids) : void{
		$actions = [];
		foreach($ids as $id){
			$actions[$id] = PacketHandlerAction::HANDLED;
		}
		$this->set($session, "handlerActions", $actions);
	}

	/**
	 * A request network settings packet for protocol 1000, which BedrockProtocol encoded where the test calls this.
	 */
	private function packet() : string{
		return RecordedPackets::next($this);
	}

	/** @param list<string> $packets */
	private function batch(array $packets) : string{
		$writer = new ByteBufferWriter();
		foreach($packets as $packet){
			VarInt::writeUnsignedInt($writer, strlen($packet));
			$writer->writeByteArray($packet);
		}
		return $writer->getData();
	}

	private function rejected(NetworkSession $session, string $payload) : void{
		try{
			$session->handleEncoded($payload);
			self::fail("Invalid packet accepted");
		}catch(PacketHandlingException){}
	}

	public function testRejectedBoundsRecordTheSizeAndLimit() : void{
		$session = $this->session();
		$this->handler($session, new class extends PacketHandler{
			public function handleEmote(\quark\network\mcpe\protocol\packet\EmotePacket $packet) : bool{ return true; }
		});
		$fields = ["actor_runtime_id" => 1, "emote_id" => str_repeat("a", 64), "emote_length_ticks" => 20, "xuid" => "", "platform_id" => "", "flags" => 0];
		//one byte over the 64 byte emote ID bound, which encoding itself refuses to write
		$tooLong = str_replace("\x40" . str_repeat("a", 64), "\x41" . str_repeat("a", 65), Protocol::encode(PacketId::EMOTE, $fields));
		$capture = Pulse::start();
		$this->rejected($session, $this->batch([$tooLong]));
		Pulse::stop();
		$event = $this->network($capture)["events"][0];
		self::assertSame(["packet.malformed", 65, 64], [$event[4], $event[5], $event[6]]);
	}

	public function testPacketDiagnosticsStayBoundedAcrossReceivePaths() : void{
		$messages = [];
		$logger = $this->createMock(\Logger::class);
		$logger->method("debug")->willReturnCallback(static function(string $message) use (&$messages) : void{ $messages[] = $message; });
		$logger->method("log")->willReturnCallback(static function(mixed $level, string $message) use (&$messages) : void{ $messages[] = $message; });
		$session = $this->session();
		$this->set($session, "logger", new \PrefixedLogger($logger, "test"));
		$padding = str_repeat("\x00", 2 * 1024 * 1024);
		$capture = Pulse::start();
		$this->rejected($session, $this->batch([$this->packet() . $padding]));
		$this->rejected($session, $this->batch(["\xff\x07" . $padding]));
		$this->rejected($session, $this->batch([substr($this->packet(), 0, -1)]));
		$compressor = $this->createMock(Compressor::class);
		$compressor->method("getNetworkType")->willReturn(CompressionAlgorithm::ZLIB);
		$compressor->method("decompress")->willThrowException(new DecompressionException("invalid compressed data"));
		$this->set($session, "compressor", $compressor);
		$this->set($session, "enableCompression", true);
		$this->rejected($session, "\x00" . $padding);
		$cipher = $this->getMockBuilder(EncryptionContext::class)->disableOriginalConstructor()->onlyMethods(["decrypt"])->getMock();
		$cipher->method("decrypt")->willThrowException(new DecryptionException("invalid checksum"));
		$this->set($session, "cipher", $cipher);
		$this->rejected($session, $padding);
		$interface = (new \ReflectionClass(RakLibInterface::class))->newInstanceWithoutConstructor();
		$this->set($interface, "sessions", [1 => $session]);
		$interface->onPacketReceive(1, $padding);
		(new Network($logger))->processRawPacket($this->createMock(AdvancedNetworkInterface::class), "192.0.2.1", 19132, $padding);
		Pulse::stop();
		self::assertCount(7, $messages);
		foreach($messages as $message){
			self::assertLessThan(1600, strlen($message));
			self::assertStringContainsString("bytes, base64:", $message);
		}
		//trailing bytes are malformed now, and the whole packet is reported rather than the unread tail
		self::assertStringContainsString("Packet processing error:", $messages[0]);
		self::assertStringContainsString((strlen($this->packet()) + strlen($padding)) . " bytes, base64:", $messages[0]);
		self::assertStringContainsString("bytes not shown", $messages[0]);
		self::assertStringContainsString("Packet processing error:", $messages[2]);
		self::assertSame(["packet.malformed", "packet.unknown", "packet.malformed", "decompression.malformed", "encryption.invalid", "transport.header"], array_column($this->network($capture)["events"], 4));
		PulseReport::create([$capture->getCapture()]);
	}

	private function itemStackPrefix(int $count) : string{
		$writer = new ByteBufferWriter();
		VarInt::writeUnsignedInt($writer, ItemStackRequestPacket::ID);
		VarInt::writeUnsignedInt($writer, $count);
		return $writer->getData();
	}

	private function authInputPrefix(int $count) : string{
		$writer = new ByteBufferWriter();
		VarInt::writeUnsignedInt($writer, PlayerAuthInputPacket::ID);
		$writer->writeByteArray(str_repeat("\x00", 32));
		VarInt::writeUnsignedInt($writer, $count);
		return $writer->getData();
	}

	private function inventoryPrefix(?int $containers = null, int $actions = 0, int $type = InventoryTransactionPacket::TYPE_NORMAL) : string{
		$writer = new ByteBufferWriter();
		VarInt::writeUnsignedInt($writer, InventoryTransactionPacket::ID);
		VarInt::writeSignedInt($writer, 0);
		$writer->writeByteArray($containers === null ? "\x00" : "\x01");
		if($containers !== null){ VarInt::writeUnsignedInt($writer, $containers); }
		VarInt::writeUnsignedInt($writer, $type);
		VarInt::writeUnsignedInt($writer, $actions);
		return $writer->getData();
	}

	public function testInventoryLimitsRejectOversizedCollections() : void{
		$session = $this->session();
		$this->inGame($session);
		$id = InventoryTransactionPacket::ID;
		//these exceed the protocol schema itself, so the native decoder rejects them before any handler runs: 101 actions,
		//11 changed containers and 257 changed slots
		$decodeLimited = [
			RecordedPackets::next($this),
			RecordedPackets::next($this),
			RecordedPackets::next($this),
			$this->inventoryPrefix(100000),
			$this->inventoryPrefix(0xffffffff),
			$this->inventoryPrefix(actions: 100000),
			$this->inventoryPrefix(actions: 0xffffffff),
		];
		//a normal transaction with 51 actions, one over the handler's limit
		try{ $session->handleDataPacket($id, RecordedPackets::next($this)); self::fail("Limit requires Pulse"); }catch(PacketHandlingException $e){
			self::assertSame("Too many actions in inventory transaction", $e->getMessage());
		}
		$capture = Pulse::start();
		Pulse::beginTick();
		//51 actions in a normal, a use item, a use item on entity and a release item transaction
		for($i = 0; $i < 4; ++$i){
			try{ $session->handleDataPacket($id, RecordedPackets::next($this)); self::fail("Oversized collection accepted"); }catch(PacketHandlingException){}
		}
		foreach($decodeLimited as $buffer){
			try{ $session->handleDataPacket($id, $buffer); self::fail("Oversized collection accepted"); }catch(PacketHandlingException){}
		}
		$this->set($session, "enableCompression", true);
		$this->set($session, "compressor", new ZlibCompressor(7, 0, ZlibCompressor::DEFAULT_MAX_DECOMPRESSION_SIZE));
		$this->rejected($session, "\x00" . Utils::assumeNotFalse(zlib_encode($this->batch([$this->inventoryPrefix(100000)]), ZLIB_ENCODING_RAW)));
		Pulse::endTick();
		Pulse::stop();
		$data = $this->network($capture);
		self::assertSame(array_merge(array_fill(0, 4, "packet.handler_validation"), array_fill(0, 8, "packet.malformed")), array_column($data["events"], 4));
		self::assertSame(array_merge(array_fill(0, 4, 51), [101, 11, 257, 100000, 4294967295, 100000, 4294967295, 100000]), array_column($data["events"], 5));
		self::assertSame(array_merge(array_fill(0, 4, InGamePacketHandler::MAX_INVENTORY_TRANSACTION_ACTIONS), [100, 10, 256, 10, 1000000, 100, 1000000, 10]), array_column($data["events"], 6));
		self::assertSame(array_fill(0, 12, InventoryTransactionPacket::ID), array_column($data["events"], 3));
		self::assertSame(array_fill(0, 12, 1), array_column($data["events"], 1));
		self::assertSame([12, 4, 8], [$data["windows"][0][3], $data["windows"][0][6], $data["windows"][0][7]]);
		PulseReport::create([$capture->getCapture()]);
	}

	public function testInventoryBoundariesPreservePacketFields() : void{
		$session = $this->session();
		$this->actions($session, [InventoryTransactionPacket::ID]);
		$handler = new class extends PacketHandler{
			/** @var list<InventoryTransactionPacket> */
			public array $packets = [];
			public function handleInventoryTransaction(InventoryTransactionPacket $packet) : bool{ $this->packets[] = $packet; return true; }
		};
		$this->handler($session, $handler);
		//no actions or slots, one action with no slots, one with 256 slots, and 50 actions over 10 containers, each from
		//sub-client 2 to sub-client 3
		for($i = 0; $i < 4; ++$i){
			$buffer = RecordedPackets::next($this);
			$session->handleEncoded($this->batch([$buffer]));
			$fields = $handler->packets[count($handler->packets) - 1];
			self::assertSame(-7, $fields->legacyRequestId);
			self::assertSame($buffer, Protocol::encode(InventoryTransactionPacket::ID, $fields, 2, 3));
		}
		self::assertCount(4, $handler->packets);
	}

	public function testNestedSlotLimitsUseControlledDisconnectsAndPreserveEvidence() : void{
		$session = $this->getMockBuilder(NetworkSession::class)->disableOriginalConstructor()->onlyMethods(["getIp", "getDisplayName", "disconnectWithError"])->getMock();
		$session->method("getIp")->willReturn("192.0.2.1");
		$session->method("getDisplayName")->willReturn("private-name");
		$session->expects(self::exactly(8))->method("disconnectWithError");
		$this->session($session);
		$this->inGame($session);
		//an inventory transaction and an auth input whose item interaction changes 257 slots, then the same with 100000
		$buffers = [];
		for($i = 0; $i < 4; ++$i){
			$buffers[] = $this->batch([RecordedPackets::next($this)]);
		}
		$this->rejected($session, $buffers[0]);
		$this->set($session, "compressor", new ZlibCompressor(7, 0, ZlibCompressor::DEFAULT_MAX_DECOMPRESSION_SIZE));
		$interface = (new \ReflectionClass(RakLibInterface::class))->newInstanceWithoutConstructor();
		$sender = $this->createMock(UserToRakLibThreadMessageSender::class);
		$sender->expects(self::exactly(8))->method("blockAddress")->with("192.0.2.1", 5);
		$this->set($interface, "interface", $sender);
		$this->set($interface, "sessions", [9000 => $session]);
		$capture = Pulse::start();
		Pulse::beginTick();
		foreach([false, true] as $compressed){
			$this->set($session, "enableCompression", $compressed);
			foreach($buffers as $buffer){
				$payload = $compressed ? "\x00" . Utils::assumeNotFalse(zlib_encode($buffer, ZLIB_ENCODING_RAW)) : $buffer;
				$interface->onPacketReceive(9000, "\xfe" . $payload);
			}
		}
		Pulse::endTick();
		Pulse::stop();
		$data = $this->network($capture);
		//the schema caps each changed slot list at 256 bytes, so the decoder rejects these and reports the size it saw
		self::assertSame(array_merge(...array_fill(0, 8, ["packet.malformed", "packet.bad_packet_disconnect"])), array_column($data["events"], 4));
		self::assertSame(array_merge(...array_fill(0, 2, [257, null, 257, null, 100000, null, 100000, null])), array_column($data["events"], 5));
		self::assertSame(array_merge(...array_fill(0, 8, [256, null])), array_column($data["events"], 6));
		self::assertSame(array_merge(...array_fill(0, 4, [InventoryTransactionPacket::ID, null, PlayerAuthInputPacket::ID, null])), array_column($data["events"], 3));
		self::assertSame(array_merge(...array_fill(0, 8, ["reject_batch", "disconnect"])), array_column($data["events"], 7));
		self::assertSame(array_fill(0, 16, 1), array_column($data["events"], 1));
		self::assertSame(array_fill(0, 16, 1), array_column($data["events"], 2));
		self::assertSame([8, 0, 8, 0], [$data["windows"][0][3], $data["windows"][0][6], $data["windows"][0][7], $data["windows"][0][8]]);
		$report = $capture->getReport()->encode();
		self::assertStringNotContainsString("private-name", $report);
		self::assertStringNotContainsString("192.0.2.1", $report);
		PulseReport::decode($report);
	}

	public function testEmbeddedInventoryActionLimitsDisconnectAndPreserveEvidence() : void{
		$session = $this->getMockBuilder(NetworkSession::class)->disableOriginalConstructor()->onlyMethods(["getIp", "getDisplayName", "disconnectWithError"])->getMock();
		$session->method("getIp")->willReturn("192.0.2.1");
		$session->method("getDisplayName")->willReturn("private-name");
		$session->expects(self::exactly(4))->method("disconnectWithError");
		$this->session($session);
		$this->actions($session, [PlayerAuthInputPacket::ID]);
		$handler = new class extends PacketHandler{
			public int $calls = 0;
			public function handlePlayerAuthInput(PlayerAuthInputPacket $packet) : bool{ ++$this->calls; return true; }
		};
		$this->handler($session, $handler);
		$this->actions($session, [PlayerAuthInputPacket::ID]);
		//auth inputs whose use item transaction carries that many actions, up to the schema's cap of 100 and past it
		$buffers = [];
		foreach([0, 1, 100, 101, 100000] as $count){
			$buffer = $this->batch([RecordedPackets::next($this)]);
			if($count <= 100){ $session->handleEncoded($buffer); }else{ $buffers[] = $buffer; }
		}
		$this->set($session, "compressor", new ZlibCompressor(7, 0, ZlibCompressor::DEFAULT_MAX_DECOMPRESSION_SIZE));
		$interface = (new \ReflectionClass(RakLibInterface::class))->newInstanceWithoutConstructor();
		$sender = $this->createMock(UserToRakLibThreadMessageSender::class);
		$sender->expects(self::exactly(4))->method("blockAddress")->with("192.0.2.1", 5);
		$this->set($interface, "interface", $sender);
		$this->set($interface, "sessions", [9000 => $session]);
		$capture = Pulse::start();
		Pulse::beginTick();
		foreach([false, true] as $compressed){
			$this->set($session, "enableCompression", $compressed);
			foreach($buffers as $buffer){
				$payload = $compressed ? "\x00" . Utils::assumeNotFalse(zlib_encode($buffer, ZLIB_ENCODING_RAW)) : $buffer;
				$interface->onPacketReceive(9000, "\xfe" . $payload);
			}
		}
		Pulse::endTick();
		Pulse::stop();
		$data = $this->network($capture);
		//the schema caps transaction actions at the same limit, so the decoder rejects these and reports the count it saw
		self::assertSame(array_merge(...array_fill(0, 4, ["packet.malformed", "packet.bad_packet_disconnect"])), array_column($data["events"], 4));
		self::assertSame(array_merge(...array_fill(0, 2, [101, null, 100000, null])), array_column($data["events"], 5));
		self::assertSame(array_merge(...array_fill(0, 4, [100, null])), array_column($data["events"], 6));
		self::assertSame(array_merge(...array_fill(0, 4, [PlayerAuthInputPacket::ID, null])), array_column($data["events"], 3));
		self::assertSame(array_merge(...array_fill(0, 4, ["reject_batch", "disconnect"])), array_column($data["events"], 7));
		self::assertSame(array_fill(0, 8, 1), array_column($data["events"], 1));
		self::assertSame(array_fill(0, 8, 1), array_column($data["events"], 2));
		self::assertSame([4, 0, 4, 0], [$data["windows"][0][3], $data["windows"][0][6], $data["windows"][0][7], $data["windows"][0][8]]);
		self::assertSame(3, $handler->calls);
		$report = $capture->getReport()->encode();
		self::assertStringNotContainsString("private-name", $report);
		self::assertStringNotContainsString("192.0.2.1", $report);
		PulseReport::decode($report);
	}

	public function testMalformedInventoryPrefixesUseControlledDisconnects() : void{
		$session = $this->getMockBuilder(NetworkSession::class)->disableOriginalConstructor()->onlyMethods(["getIp", "getDisplayName", "disconnectWithError"])->getMock();
		$session->method("getIp")->willReturn("192.0.2.1");
		$session->method("getDisplayName")->willReturn("private-name");
		$this->session($session);
		$this->actions($session, [InventoryTransactionPacket::ID]);
		$prefix = $this->inventoryPrefix();
		$buffers = [$this->inventoryPrefix(type: 5), $this->inventoryPrefix(type: 0xffffffff), $this->inventoryPrefix(1)];
		for($length = 1; $length < strlen($prefix); ++$length){ $buffers[] = substr($prefix, 0, $length); }
		$header = substr($this->inventoryPrefix(0), 0, -3);
		foreach(["\x00", "\x00\x80", "\x00\xff\xff\xff\xff\x0f", "\x00" . str_repeat("\x80", 6)] as $slots){ $buffers[] = $header . "\x01" . $slots; }
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

	/**
	 * An auth input from sub-client 2 to 3 at (1, 2, 3) on tick 123, with its recorded empty flag list replaced.
	 *
	 * @param list<int> $flags
	 */
	private function authInputPacket(array $flags) : string{
		$buffer = RecordedPackets::next($this);
		$stream = new ByteBufferReader($buffer);
		VarInt::readUnsignedInt($stream);
		$stream->setOffset($stream->getOffset() + 32);
		$prefixLength = $stream->getOffset();
		self::assertSame(0, VarInt::readUnsignedInt($stream));
		$writer = new ByteBufferWriter();
		$writer->writeByteArray(substr($buffer, 0, $prefixLength));
		VarInt::writeUnsignedInt($writer, count($flags));
		foreach($flags as $flag){ VarInt::writeSignedInt($writer, $flag); }
		$writer->writeByteArray(substr($buffer, $stream->getOffset()));
		return $writer->getData();
	}

	public function testInputFlagLimitsRejectOversizedLists() : void{
		$session = $this->session();
		$this->inGame($session);
		$packet = PlayerAuthInputPacket::ID;
		$oversized = $this->authInputPacket(range(0, PlayerAuthInputFlags::NUMBER_OF_FLAGS));
		try{ $session->handleDataPacket($packet, $oversized); self::fail("Limit requires Pulse"); }catch(PacketHandlingException $e){
			self::assertSame("Too many input flags in PlayerAuthInputPacket", $e->getMessage());
		}
		$capture = Pulse::start();
		Pulse::beginTick();
		try{ $session->handleDataPacket($packet, $oversized); self::fail("Oversized flag list accepted"); }catch(PacketHandlingException){}
		//a declared count the payload cannot hold fails inside the decoder
		foreach([1000000, 0xffffffff] as $count){
			try{ $session->handleDataPacket($packet, $this->authInputPrefix($count)); self::fail("Oversized flag list accepted"); }catch(PacketHandlingException){}
		}
		$batch = $this->batch([$this->authInputPrefix(1000000) . str_repeat("\x00", 1000000)]);
		$this->set($session, "enableCompression", true);
		$this->set($session, "compressor", new ZlibCompressor(7, 0, ZlibCompressor::DEFAULT_MAX_DECOMPRESSION_SIZE));
		$this->rejected($session, "\x00" . Utils::assumeNotFalse(zlib_encode($batch, ZLIB_ENCODING_RAW)));
		Pulse::endTick();
		Pulse::stop();
		$data = $this->network($capture);
		self::assertSame(["packet.handler_validation", "packet.malformed", "packet.malformed", "packet.malformed"], array_column($data["events"], 4));
		self::assertSame([PlayerAuthInputFlags::NUMBER_OF_FLAGS + 1, 1000000, 4294967295, 1000000], array_column($data["events"], 5));
		self::assertSame([PlayerAuthInputFlags::NUMBER_OF_FLAGS, 128, 1000000, 128], array_column($data["events"], 6));
		self::assertSame(array_fill(0, 4, PlayerAuthInputPacket::ID), array_column($data["events"], 3));
		self::assertSame(array_fill(0, 4, 1), array_column($data["events"], 1));
		self::assertSame([4, 1, 3], [$data["windows"][0][3], $data["windows"][0][6], $data["windows"][0][7]]);
		PulseReport::create([$capture->getCapture()]);
	}

	public function testInputFlagBoundariesPreservePacketFields() : void{
		$session = $this->session();
		$this->actions($session, [PlayerAuthInputPacket::ID]);
		$handler = new class extends PacketHandler{
			/** @var list<PlayerAuthInputPacket> */
			public array $packets = [];
			public function handlePlayerAuthInput(PlayerAuthInputPacket $packet) : bool{ $this->packets[] = $packet; return true; }
		};
		$this->handler($session, $handler);
		$cases = [[], [0], range(0, PlayerAuthInputFlags::NUMBER_OF_FLAGS - 1)];
		$buffers = [];
		foreach($cases as $flags){
			$buffers[] = $buffer = $this->authInputPacket($flags);
			$session->handleEncoded($this->batch([$buffer]));
		}
		self::assertCount(3, $handler->packets);
		foreach($handler->packets as $index => $packet){
			self::assertSame([123, 4.0, 5.0, 6.0], [$packet->clientTick, $packet->playerRotation->x, $packet->playerRotation->y, $packet->playerHeadRotation]);
			self::assertEquals(new Vector3(1.0, 2.0, 3.0), $packet->position);
			self::assertSame($cases[$index], $packet->inputData);
			self::assertSame($buffers[$index], Protocol::encode(PlayerAuthInputPacket::ID, $packet, 2, 3));
		}
	}

	public function testMalformedInputFlagsUseControlledDisconnects() : void{
		$session = $this->getMockBuilder(NetworkSession::class)->disableOriginalConstructor()->onlyMethods(["getIp", "getDisplayName", "disconnectWithError"])->getMock();
		$session->method("getIp")->willReturn("192.0.2.1");
		$session->method("getDisplayName")->willReturn("private-name");
		$this->session($session);
		$this->inGame($session);
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
		//flag values are checked by the handler once the list decodes, truncated lists fail in the decoder
		self::assertSame(array_merge(
			array_merge(...array_fill(0, 4, ["packet.handler_validation", "packet.bad_packet_disconnect"])),
			array_merge(...array_fill(0, count($buffers) - 4, ["packet.malformed", "packet.bad_packet_disconnect"]))
		), array_column($data["events"], 4));
		self::assertSame([count($buffers), 4, count($buffers) - 4], [$data["windows"][0][3], $data["windows"][0][6], $data["windows"][0][7]]);
	}

	public function testItemStackRequestLimitsRejectOversizedLists() : void{
		$session = $this->session();
		$this->inGame($session);
		$packet = ItemStackRequestPacket::ID;
		//81 requests of one lab table combine action each, one over the handler's limit
		$tooMany = RecordedPackets::next($this);
		try{ $session->handleDataPacket($packet, $tooMany); self::fail("Limit requires Pulse"); }catch(PacketHandlingException $e){
			self::assertSame("Too many requests in ItemStackRequestPacket", $e->getMessage());
		}
		$capture = Pulse::start();
		Pulse::beginTick();
		try{ $session->handleDataPacket($packet, $tooMany); self::fail("Oversized collection accepted"); }catch(PacketHandlingException){}
		//past the schema's own cap of 100 requests the decoder refuses the count before reading any request
		foreach([101, 100000, 0xffffffff] as $count){
			try{ $session->handleDataPacket($packet, $this->itemStackPrefix($count)); self::fail("Oversized collection accepted"); }catch(PacketHandlingException){}
		}
		//an empty request: ID 0, no actions, no filter strings and a 32-bit filter cause, which the encoder refuses to write
		$request = str_repeat("\x00", 7);
		$batch = $this->batch([$this->itemStackPrefix(100000) . str_repeat($request, 100000)]);
		$this->set($session, "enableCompression", true);
		$this->set($session, "compressor", new ZlibCompressor(7, 0, ZlibCompressor::DEFAULT_MAX_DECOMPRESSION_SIZE));
		$this->rejected($session, "\x00" . Utils::assumeNotFalse(zlib_encode($batch, ZLIB_ENCODING_RAW)));
		Pulse::endTick();
		Pulse::stop();
		$data = $this->network($capture);
		self::assertSame(array_merge(["packet.handler_validation"], array_fill(0, 4, "packet.malformed")), array_column($data["events"], 4));
		self::assertSame([InGamePacketHandler::MAX_ITEM_STACK_REQUESTS + 1, 101, 100000, 4294967295, 100000], array_column($data["events"], 5));
		self::assertSame([InGamePacketHandler::MAX_ITEM_STACK_REQUESTS, 100, 100, 1000000, 100], array_column($data["events"], 6));
		self::assertSame(array_fill(0, 5, "reject_batch"), array_column($data["events"], 7));
		self::assertSame(array_fill(0, 5, 1), array_column($data["events"], 1));
		self::assertSame([5, 1, 4], [$data["windows"][0][3], $data["windows"][0][6], $data["windows"][0][7]]);
		PulseReport::create([$capture->getCapture()]);
	}

	public function testNestedItemStackLimitsPreserveEvidenceAcrossPacketPaths() : void{
		$session = $this->session();
		$this->inGame($session);
		$buffers = [];
		//one request of that many lab table combine actions, standalone and inside an auth input
		foreach([61, 100000] as $count){
			for($i = 0; $i < 2; ++$i){
				$buffers[] = [$this->batch([RecordedPackets::next($this)]), $count];
			}
		}
		$this->rejected($session, $buffers[0][0]);
		$this->set($session, "compressor", new ZlibCompressor(7, 0, ZlibCompressor::DEFAULT_MAX_DECOMPRESSION_SIZE));
		$capture = Pulse::start();
		Pulse::beginTick();
		foreach([false, true] as $compressed){
			$this->set($session, "enableCompression", $compressed);
			foreach($buffers as [$buffer, $count]){
				$payload = $compressed ? "\x00" . Utils::assumeNotFalse(zlib_encode($buffer, ZLIB_ENCODING_RAW)) : $buffer;
				try{ $session->handleEncoded($payload); self::fail("Oversized item-stack actions accepted"); }catch(PacketHandlingException $e){
					//61 actions decode and the handler reports the size, 100000 exceed the schema's cap of 100
					$cause = $e->getPrevious();
					self::assertInstanceOf(PacketHandlingException::class, $cause);
					self::assertSame($count === 61 ? [61, 60] : [null, null], [$cause->observed, $cause->limit]);
				}
			}
		}
		Pulse::endTick();
		Pulse::stop();
		$data = $this->network($capture);
		$events = array_merge(...array_fill(0, 2, ["packet.handler_validation", "packet.handler_validation", "packet.malformed", "packet.malformed"]));
		self::assertSame($events, array_column($data["events"], 4));
		self::assertSame(array_merge(...array_fill(0, 2, [61, 61, 100000, 100000])), array_column($data["events"], 5));
		self::assertSame(array_merge(...array_fill(0, 2, [60, 60, 100, 100])), array_column($data["events"], 6));
		self::assertSame(array_merge(...array_fill(0, 4, [ItemStackRequestPacket::ID, PlayerAuthInputPacket::ID])), array_column($data["events"], 3));
		self::assertSame(array_fill(0, 8, "reject_batch"), array_column($data["events"], 7));
		self::assertSame(array_fill(0, 8, 1), array_column($data["events"], 1));
		self::assertSame(array_fill(0, 8, 1), array_column($data["events"], 2));
		self::assertSame([8, 4, 4, 0], [$data["windows"][0][3], $data["windows"][0][6], $data["windows"][0][7], $data["windows"][0][8]]);
		PulseReport::decode($capture->getReport()->encode());
	}

	public function testItemStackRequestBoundariesAndMalformedPrefixes() : void{
		$session = $this->session();
		$this->actions($session, [ItemStackRequestPacket::ID]);
		$handler = new class extends PacketHandler{
			/** @var list<ItemStackRequestPacket> */
			public array $packets = [];
			public function handleItemStackRequest(ItemStackRequestPacket $packet) : bool{
				$this->packets[] = $packet;
				return true;
			}
		};
		$this->handler($session, $handler);
		$capture = Pulse::start();
		$buffers = [];
		//none, one and the handler's limit of requests with one lab table combine action and a filter string, from
		//sub-client 2 to sub-client 3
		for($i = 0; $i < 3; ++$i){
			$buffers[] = $buffer = RecordedPackets::next($this);
			$session->handleEncoded($this->batch([$buffer]));
		}
		self::assertCount(3, $handler->packets);
		foreach($handler->packets as $index => $packet){
			foreach($packet->requests as $request){ self::assertSame(["filter"], $request->stringsToFilter); }
			self::assertSame($buffers[$index], Protocol::encode(ItemStackRequestPacket::ID, $packet, 2, 3));
		}
		$header = substr($this->itemStackPrefix(0), 0, -1);
		foreach(["", $header, $header . "\x80", $header . str_repeat("\x80", 6), "\x00\x51"] as $buffer){
			try{ $session->handleDataPacket(ItemStackRequestPacket::ID, $buffer); self::fail("Malformed prefix accepted"); }catch(PacketHandlingException){}
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
			foreach([ItemStackRequestPacket::class => $this->itemStackPrefix(81), PlayerAuthInputPacket::class => $this->authInputPrefix(67), InventoryTransactionPacket::class => $this->inventoryPrefix(actions: 51)] as $class => $buffer){
				$this->actions($session, [$class::ID]);
				$payload = $this->batch([$buffer]);
				$session->handleEncoded($payload);
				$this->actions($session, []);
				$this->rejected($session, $payload);
			}
		}finally{
			$list->unregister($listener);
			Pulse::stop();
		}
		$data = $this->network($capture);
		//packets the handler has no method for are decoded natively when a plugin asks for them, within the same bounds
		self::assertSame(array_fill(0, 3, "packet.malformed"), array_column($data["events"], 4));
		self::assertSame([6, 0, 3, 3, 0], [$data["windows"][0][3], $data["windows"][0][6], $data["windows"][0][7], $data["windows"][0][8], $data["windows"][0][9]]);
	}

	public function testItemStackHandlerStillLimitsAlreadyDecodedRequests() : void{
		$handler = (new \ReflectionClass(InGamePacketHandler::class))->newInstanceWithoutConstructor();
		$request = new ItemStackRequestPacketData(0, [], [], 0);
		try{ $handler->handleItemStackRequest(new ItemStackRequestPacket(array_fill(0, InGamePacketHandler::MAX_ITEM_STACK_REQUESTS + 1, $request))); self::fail("Handler accepted oversized request list"); }catch(PacketHandlingException $e){
			self::assertSame([InGamePacketHandler::MAX_ITEM_STACK_REQUESTS + 1, InGamePacketHandler::MAX_ITEM_STACK_REQUESTS], [$e->observed, $e->limit]);
		}
	}

	public function testInventoryHandlerStillLimitsAlreadyDecodedPackets() : void{
		$handler = (new \ReflectionClass(InGamePacketHandler::class))->newInstanceWithoutConstructor();
		$item = new \quark\network\mcpe\protocol\types\NetworkItemStackDescriptor(0, 0, 0, null, 0, "");
		$action = new \quark\network\mcpe\protocol\types\InventoryAction(new \quark\network\mcpe\protocol\types\InventorySource(0, 0), 0, $item, $item);
		foreach([
			[new InventoryTransactionPacket(0, null, ["normal_transaction_data" => array_fill(0, 51, $action)]), "Too many actions in inventory transaction", 51, InGamePacketHandler::MAX_INVENTORY_TRANSACTION_ACTIONS],
		] as [$packet, $message, $observed, $limit]){
			try{ $handler->handleInventoryTransaction($packet); self::fail("Handler accepted oversized inventory list"); }catch(PacketHandlingException $e){
				self::assertSame([$message, $observed, $limit], [$e->getMessage(), $e->observed, $e->limit]);
			}
		}
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
		$this->handler($session, $handler);
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
			$this->handler($session, new class extends PacketHandler{
				public function handleRequestNetworkSettings(RequestNetworkSettingsPacket $packet) : bool{ throw new \RuntimeException("plugin failure"); }
			});
			try{ $session->handleEncoded($payload); self::fail("Handler failure ignored"); }catch(\RuntimeException $e){ self::assertSame("plugin failure", $e->getMessage()); }
			self::assertSame("", $watchdog->record);
			$this->handler($session, new class extends PacketHandler{
				public function handleRequestNetworkSettings(RequestNetworkSettingsPacket $packet) : bool{ throw new \InvalidArgumentException("handler failure"); }
			});
			try{ $session->handleDataPacket(RequestNetworkSettingsPacket::ID, $this->packet()); self::fail("Unrelated handler failure hidden"); }catch(\InvalidArgumentException $e){ self::assertSame("handler failure", $e->getMessage()); }
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
		$this->actions($session, []);
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
		self::assertTrue($handler->handleRequestNetworkSettings(new RequestNetworkSettingsPacket(1)));
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
		self::assertSame([RequestNetworkSettingsPacket::ID, 1023], array_column($data["events"], 3));
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

	public function testNestedCollectionsRejectWithPulseEvidenceOnBothReceivePaths() : void{
		$session = $this->getMockBuilder(NetworkSession::class)->disableOriginalConstructor()->onlyMethods(["getIp", "getDisplayName", "disconnectWithError"])->getMock();
		$session->method("getIp")->willReturn("192.0.2.1");
		$session->method("getDisplayName")->willReturn("private-name");
		$this->session($session);
		$this->actions($session, [InventoryTransactionPacket::ID, PlayerAuthInputPacket::ID, ItemStackRequestPacket::ID]);
		$handler = new class extends PacketHandler{
			/** @var array<string, int> */
			public array $calls = [];
			public function handleInventoryTransaction(InventoryTransactionPacket $packet) : bool{ $this->calls[__FUNCTION__] = ($this->calls[__FUNCTION__] ?? 0) + 1; return true; }
			public function handlePlayerAuthInput(PlayerAuthInputPacket $packet) : bool{ $this->calls[__FUNCTION__] = ($this->calls[__FUNCTION__] ?? 0) + 1; return true; }
			public function handleItemStackRequest(ItemStackRequestPacket $packet) : bool{ $this->calls[__FUNCTION__] = ($this->calls[__FUNCTION__] ?? 0) + 1; return true; }
		};
		$this->handler($session, $handler);
		$buffers = [];
		$ids = [];
		//changed containers in an inventory transaction, and filter strings, auto craft ingredients and deprecated craft
		//results in an item stack request, each standalone and inside an auth input, at 1, the schema's cap, one over it
		//and 100000
		foreach([["containers", 10], ["filters", 256], ["ingredients", 128], ["results", 256]] as [$kind, $limit]){
			$standalone = $kind === "containers" ? InventoryTransactionPacket::ID : ItemStackRequestPacket::ID;
			foreach([1, $limit, $limit + 1, 100000] as $count){
				foreach([$standalone, PlayerAuthInputPacket::ID] as $id){
					$buffer = $this->batch([RecordedPackets::next($this)]);
					if($count <= $limit){ $session->handleEncoded($buffer); }else{
						$buffers[] = $buffer;
						$ids[] = $id; $ids[] = null;
					}
				}
			}
		}
		$session->expects(self::exactly(count($buffers) * 2))->method("disconnectWithError");
		$this->set($session, "compressor", new ZlibCompressor(7, 0, ZlibCompressor::DEFAULT_MAX_DECOMPRESSION_SIZE));
		$interface = (new \ReflectionClass(RakLibInterface::class))->newInstanceWithoutConstructor();
		$sender = $this->createMock(UserToRakLibThreadMessageSender::class);
		$sender->expects(self::exactly(count($buffers) * 2))->method("blockAddress")->with("192.0.2.1", 5);
		$this->set($interface, "interface", $sender);
		$this->set($interface, "sessions", [9000 => $session]);
		$capture = Pulse::start();
		Pulse::beginTick();
		foreach([false, true] as $compressed){
			$this->set($session, "enableCompression", $compressed);
			foreach($buffers as $buffer){
				$payload = $compressed ? "\x00" . Utils::assumeNotFalse(zlib_encode($buffer, ZLIB_ENCODING_RAW)) : $buffer;
				$interface->onPacketReceive(9000, "\xfe" . $payload);
			}
		}
		Pulse::endTick(); Pulse::stop();
		$data = $this->network($capture);
		//each of these caps is part of the protocol schema, so the decoder rejects the packet and reports the size it saw
		self::assertSame(array_merge(...array_fill(0, count($buffers) * 2, ["packet.malformed", "packet.bad_packet_disconnect"])), array_column($data["events"], 4));
		self::assertSame(array_merge(...array_fill(0, 2, [11, null, 11, null, 100000, null, 100000, null, 257, null, 257, null, 100000, null, 100000, null, 129, null, 129, null, 100000, null, 100000, null, 257, null, 257, null, 100000, null, 100000, null])), array_column($data["events"], 5));
		self::assertSame(array_merge(...array_fill(0, 2, [10, null, 10, null, 10, null, 10, null, 256, null, 256, null, 256, null, 256, null, 128, null, 128, null, 128, null, 128, null, 256, null, 256, null, 256, null, 256, null])), array_column($data["events"], 6));
		self::assertSame(array_merge($ids, $ids), array_column($data["events"], 3));
		self::assertSame(array_merge(...array_fill(0, count($buffers) * 2, ["reject_batch", "disconnect"])), array_column($data["events"], 7));
		self::assertSame(array_fill(0, count($buffers) * 4, 1), array_column($data["events"], 1));
		self::assertSame(array_fill(0, count($buffers) * 4, 1), array_column($data["events"], 2));
		self::assertSame([count($buffers) * 2, 0, count($buffers) * 2, 0], [$data["windows"][0][3], $data["windows"][0][6], $data["windows"][0][7], $data["windows"][0][8]]);
		self::assertSame(["handleInventoryTransaction" => 2, "handlePlayerAuthInput" => 8, "handleItemStackRequest" => 6], $handler->calls);
		$report = $capture->getReport()->encode();
		self::assertStringNotContainsString("private-name", $report);
		self::assertStringNotContainsString("192.0.2.1", $report);
		PulseReport::decode($report);
	}
}
