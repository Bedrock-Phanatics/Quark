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
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\ItemStackRequestPacket;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\RequestNetworkSettingsPacket;
use pocketmine\network\mcpe\protocol\serializer\BitSet;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\CompressionAlgorithm;
use pocketmine\network\mcpe\protocol\types\InputMode;
use pocketmine\network\mcpe\protocol\types\InteractionMode;
use pocketmine\network\mcpe\protocol\types\inventory\HandSlot;
use pocketmine\network\mcpe\protocol\types\inventory\InventoryTransactionChangedSlotsHack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\inventory\NetworkInventoryAction;
use pocketmine\network\mcpe\protocol\types\inventory\NormalTransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\PredictedResult;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\CraftRecipeAutoStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\DeprecatedCraftingResultsEntry;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\DeprecatedCraftingResultsStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequest;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\LabTableCombineStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\TransactionData;
use pocketmine\network\mcpe\protocol\types\inventory\TriggerType;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\ItemInteractionData;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use pocketmine\network\mcpe\protocol\types\PlayMode;
use pocketmine\network\mcpe\protocol\types\recipe\RecipeIngredient;
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
		$legacy = [];
		foreach($methods as $id => [$method, $takesFields]){
			$closures[$id] = (new \ReflectionMethod($handler, $method))->getClosure($handler);
			if(!$takesFields){
				$legacy[$id] = true;
			}
		}
		$this->set($session, "handler", $handler);
		$this->set($session, "handlerActions", $actions);
		$this->set($session, "handlerMethods", $closures);
		$this->set($session, "legacyHandlers", $legacy);
	}

	/**
	 * Marks packets as handled through BedrockProtocol objects, whatever the current handler implements.
	 *
	 * @param list<int> $ids
	 */
	private function actions(NetworkSession $session, array $ids) : void{
		$actions = [];
		$legacy = [];
		foreach($ids as $id){
			$actions[$id] = PacketHandlerAction::HANDLED;
			$legacy[$id] = true;
		}
		$this->set($session, "handlerActions", $actions);
		$this->set($session, "legacyHandlers", $legacy);
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

	public function testPacketDiagnosticsStayBoundedAcrossReceivePaths() : void{
		$messages = [];
		$logger = $this->createMock(\Logger::class);
		$logger->method("debug")->willReturnCallback(static function(string $message) use (&$messages) : void{ $messages[] = $message; });
		$logger->method("log")->willReturnCallback(static function(mixed $level, string $message) use (&$messages) : void{ $messages[] = $message; });
		$session = $this->session();
		$this->set($session, "logger", new \PrefixedLogger($logger, "test"));
		$padding = str_repeat("\x00", 2 * 1024 * 1024);
		$capture = Pulse::start();
		$session->handleEncoded($this->batch([$this->packet() . $padding]));
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
		self::assertStringContainsString("2097152 bytes, base64:", $messages[0]);
		self::assertStringContainsString("2096128 bytes not shown", $messages[0]);
		self::assertStringContainsString("Packet processing error:", $messages[2]);
		self::assertSame(["packet.unknown", "packet.malformed", "decompression.malformed", "encryption.invalid", "transport.header"], array_column($this->network($capture)["events"], 4));
		PulseReport::create([$capture->getCapture()]);
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

	private function inventoryPrefix(?int $containers = null, int $actions = 0, int $type = InventoryTransactionPacket::TYPE_NORMAL) : string{
		$writer = new ByteBufferWriter();
		VarInt::writeUnsignedInt($writer, InventoryTransactionPacket::NETWORK_ID);
		VarInt::writeSignedInt($writer, 0);
		$writer->writeByteArray($containers === null ? "\x00" : "\x01");
		if($containers !== null){ VarInt::writeUnsignedInt($writer, $containers); }
		VarInt::writeUnsignedInt($writer, $type);
		VarInt::writeUnsignedInt($writer, $actions);
		return $writer->getData();
	}

	public function testInventoryLimitsRunBeforeDecoding() : void{
		$session = $this->session();
		$packet = InventoryTransactionPacket::NETWORK_ID;
		$this->actions($session, [InventoryTransactionPacket::NETWORK_ID]);
		try{ $session->handleDataPacket($packet, $this->inventoryPrefix(actions: 51)); self::fail("Limit requires Pulse"); }catch(PacketHandlingException $e){
			self::assertSame("Too many actions in inventory transaction", $e->getMessage());
		}
		$capture = Pulse::start();
		Pulse::beginTick();
		foreach([11, 100000, 0xffffffff] as $count){
			try{ $session->handleDataPacket($packet, $this->inventoryPrefix($count)); self::fail("Oversized slot sync list accepted"); }catch(PacketHandlingException $e){
				self::assertSame("Too many slot sync requests in inventory transaction", $e->getMessage());
			}
		}
		foreach(range(InventoryTransactionPacket::TYPE_NORMAL, InventoryTransactionPacket::TYPE_RELEASE_ITEM) as $type){
			try{ $session->handleDataPacket($packet, $this->inventoryPrefix(actions: 51, type: $type)); self::fail("Oversized action list accepted"); }catch(PacketHandlingException){}
		}
		foreach([100000, 0xffffffff] as $count){
			try{ $session->handleDataPacket($packet, $this->inventoryPrefix(actions: $count)); self::fail("Oversized action list accepted"); }catch(PacketHandlingException){}
		}
		$batch = $this->batch([$this->inventoryPrefix(100000)]);
		$this->set($session, "enableCompression", true);
		$this->set($session, "compressor", new ZlibCompressor(7, 0, ZlibCompressor::DEFAULT_MAX_DECOMPRESSION_SIZE));
		$this->rejected($session, "\x00" . Utils::assumeNotFalse(zlib_encode($batch, ZLIB_ENCODING_RAW)));
		Pulse::endTick();
		Pulse::stop();
		$data = $this->network($capture);
		self::assertSame(array_fill(0, 11, "packet.handler_validation"), array_column($data["events"], 4));
		self::assertSame([11, 100000, 0xffffffff, 51, 51, 51, 51, 51, 100000, 0xffffffff, 100000], array_column($data["events"], 5));
		self::assertSame([10, 10, 10, 50, 50, 50, 50, 50, 50, 50, 10], array_column($data["events"], 6));
		self::assertSame(array_fill(0, 11, InventoryTransactionPacket::NETWORK_ID), array_column($data["events"], 3));
		self::assertSame(array_fill(0, 11, 1), array_column($data["events"], 1));
		self::assertSame([11, 0, 11], [$data["windows"][0][3], $data["windows"][0][6], $data["windows"][0][7]]);
		PulseReport::create([$capture->getCapture()]);
	}

	public function testInventoryBoundariesPreservePacketFields() : void{
		$session = $this->session();
		$this->actions($session, [InventoryTransactionPacket::NETWORK_ID]);
		$handler = new class extends PacketHandler{
			/** @var list<InventoryTransactionPacket> */
			public array $packets = [];
			public function handleInventoryTransaction(InventoryTransactionPacket $packet) : bool{ $this->packets[] = $packet; return true; }
		};
		$this->handler($session, $handler);
		$action = new NetworkInventoryAction();
		$action->sourceType = NetworkInventoryAction::SOURCE_CONTAINER;
		$action->windowId = 0;
		$action->inventorySlot = 1;
		$action->oldItem = $action->newItem = ItemStackWrapper::legacy(ItemStack::null());
		foreach([[0, null], [1, []], [1, [new InventoryTransactionChangedSlotsHack(2, range(0, 255))]], [50, array_fill(0, 10, new InventoryTransactionChangedSlotsHack(2, [0, 1, 255]))]] as [$count, $slots]){
			$packet = InventoryTransactionPacket::create(-7, $slots, NormalTransactionData::new(array_fill(0, $count, $action)));
			$packet->senderSubId = 2;
			$packet->recipientSubId = 3;
			$writer = new ByteBufferWriter();
			$packet->encode($writer);
			$session->handleEncoded($this->batch([$writer->getData()]));
			$decoded = $handler->packets[count($handler->packets) - 1];
			self::assertEquals($packet, $decoded);
			$encoded = new ByteBufferWriter();
			$decoded->encode($encoded);
			self::assertSame($writer->getData(), $encoded->getData());
		}
		self::assertCount(4, $handler->packets);
	}

	public function testNestedSlotLimitsUseControlledDisconnectsAndPreserveEvidence() : void{
		$session = $this->getMockBuilder(NetworkSession::class)->disableOriginalConstructor()->onlyMethods(["getIp", "getDisplayName", "disconnectWithError"])->getMock();
		$session->method("getIp")->willReturn("192.0.2.1");
		$session->method("getDisplayName")->willReturn("private-name");
		$session->expects(self::exactly(8))->method("disconnectWithError");
		$this->session($session);
		$this->actions($session, [InventoryTransactionPacket::NETWORK_ID, PlayerAuthInputPacket::NETWORK_ID]);
		$transaction = UseItemTransactionData::new([], 0, TriggerType::PLAYER_INPUT, new BlockPosition(0, 0, 0), 0, 0, HandSlot::MAINHAND, ItemStackWrapper::legacy(ItemStack::null()), new Vector3(1, 2, 3), new Vector3(0, 0, 0), 0, PredictedResult::SUCCESS, 0);
		$buffers = [];
		foreach([257, 100000] as $count){
			$slots = new InventoryTransactionChangedSlotsHack(2, array_fill(0, $count, 0));
			foreach([
				InventoryTransactionPacket::create(-7, [$slots], NormalTransactionData::new([])),
				PlayerAuthInputPacket::create(
					new Vector3(1, 2, 3), 4, 5, 6, 0, 0, new BitSet(PlayerAuthInputFlags::NUMBER_OF_FLAGS),
					InputMode::MOUSE_KEYBOARD, PlayMode::NORMAL, InteractionMode::TOUCH, new Vector2(0, 0), 123, new Vector3(0, 0, 0),
					new ItemInteractionData(-7, [$slots], $transaction), null, null, null,
					0, 0, new Vector3(0, 0, 0), new Vector2(0, 0)
				)
			] as $packet){
				$writer = new ByteBufferWriter();
				$packet->encode($writer);
				$buffers[] = $this->batch([$writer->getData()]);
			}
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
		self::assertSame(array_merge(...array_fill(0, 8, ["packet.handler_validation", "packet.bad_packet_disconnect"])), array_column($data["events"], 4));
		self::assertSame(array_merge(...array_fill(0, 2, [257, null, 257, null, 100000, null, 100000, null])), array_column($data["events"], 5));
		self::assertSame(array_merge(...array_fill(0, 8, [InventoryTransactionChangedSlotsHack::MAX_CHANGED_SLOTS, null])), array_column($data["events"], 6));
		self::assertSame(array_merge(...array_fill(0, 4, [InventoryTransactionPacket::NETWORK_ID, null, PlayerAuthInputPacket::NETWORK_ID, null])), array_column($data["events"], 3));
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
		$this->actions($session, [PlayerAuthInputPacket::NETWORK_ID]);
		$handler = new class extends PacketHandler{
			public int $calls = 0;
			public function handlePlayerAuthInput(PlayerAuthInputPacket $packet) : bool{ ++$this->calls; return true; }
		};
		$this->handler($session, $handler);
		$this->actions($session, [PlayerAuthInputPacket::NETWORK_ID]);
		$action = new NetworkInventoryAction();
		$action->sourceType = NetworkInventoryAction::SOURCE_CONTAINER;
		$action->windowId = 2;
		$action->inventorySlot = 7;
		$action->oldItem = $action->newItem = ItemStackWrapper::legacy(ItemStack::null());
		$buffers = [];
		foreach([0, 1, TransactionData::MAX_ACTIONS, 101, 100000] as $count){
			$transaction = UseItemTransactionData::new(array_fill(0, $count, $action), 0, TriggerType::PLAYER_INPUT, new BlockPosition(0, 0, 0), 0, 0, HandSlot::MAINHAND, ItemStackWrapper::legacy(ItemStack::null()), new Vector3(1, 2, 3), new Vector3(0, 0, 0), 0, PredictedResult::SUCCESS, 0);
			$packet = PlayerAuthInputPacket::create(new Vector3(1, 2, 3), 4, 5, 6, 0, 0, new BitSet(PlayerAuthInputFlags::NUMBER_OF_FLAGS), InputMode::MOUSE_KEYBOARD, PlayMode::NORMAL, InteractionMode::TOUCH, new Vector2(0, 0), 123, new Vector3(0, 0, 0), new ItemInteractionData(-7, null, $transaction), null, null, null, 0, 0, new Vector3(0, 0, 0), new Vector2(0, 0));
			$writer = new ByteBufferWriter();
			$packet->encode($writer);
			$buffer = $this->batch([$writer->getData()]);
			if($count <= TransactionData::MAX_ACTIONS){ $session->handleEncoded($buffer); }else{ $buffers[] = $buffer; }
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
		self::assertSame(array_merge(...array_fill(0, 4, ["packet.handler_validation", "packet.bad_packet_disconnect"])), array_column($data["events"], 4));
		self::assertSame([101, null, 100000, null, 101, null, 100000, null], array_column($data["events"], 5));
		self::assertSame(array_merge(...array_fill(0, 4, [TransactionData::MAX_ACTIONS, null])), array_column($data["events"], 6));
		self::assertSame(array_merge(...array_fill(0, 4, [PlayerAuthInputPacket::NETWORK_ID, null])), array_column($data["events"], 3));
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
		$this->actions($session, [InventoryTransactionPacket::NETWORK_ID]);
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

	/** @param list<int> $flags */
	private function authInputPacket(array $flags) : string{
		$packet = PlayerAuthInputPacket::create(
			new Vector3(1, 2, 3), 4, 5, 6, 0, 0, new BitSet(PlayerAuthInputFlags::NUMBER_OF_FLAGS),
			InputMode::MOUSE_KEYBOARD, PlayMode::NORMAL, InteractionMode::TOUCH, new Vector2(0, 0), 123, new Vector3(0, 0, 0), null, null, null, null,
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
		$packet = PlayerAuthInputPacket::NETWORK_ID;
		$this->actions($session, [PlayerAuthInputPacket::NETWORK_ID]);
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
		$this->actions($session, [PlayerAuthInputPacket::NETWORK_ID]);
		$handler = new class extends PacketHandler{
			/** @var list<PlayerAuthInputPacket> */
			public array $packets = [];
			public function handlePlayerAuthInput(PlayerAuthInputPacket $packet) : bool{ $this->packets[] = $packet; return true; }
		};
		$this->handler($session, $handler);
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
		$this->actions($session, [PlayerAuthInputPacket::NETWORK_ID]);
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
		$packet = ItemStackRequestPacket::NETWORK_ID;
		$this->actions($session, [ItemStackRequestPacket::NETWORK_ID]);
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

	public function testNestedItemStackLimitsPreserveEvidenceAcrossPacketPaths() : void{
		$session = $this->session();
		$this->actions($session, [ItemStackRequestPacket::NETWORK_ID, PlayerAuthInputPacket::NETWORK_ID]);
		$buffers = [];
		foreach([61, 100000] as $count){
			$request = new ItemStackRequest(-7, array_fill(0, $count, new LabTableCombineStackRequestAction()), ["filter"], 0);
			foreach([
				ItemStackRequestPacket::create([$request]),
				PlayerAuthInputPacket::create(
					new Vector3(1, 2, 3), 4, 5, 6, 0, 0, new BitSet(PlayerAuthInputFlags::NUMBER_OF_FLAGS),
					InputMode::MOUSE_KEYBOARD, PlayMode::NORMAL, InteractionMode::TOUCH, new Vector2(0, 0), 123, new Vector3(0, 0, 0), null, $request, null, null,
					0, 0, new Vector3(0, 0, 0), new Vector2(0, 0)
				)
			] as $packet){
				$writer = new ByteBufferWriter();
				$packet->encode($writer);
				$buffers[] = [$this->batch([$writer->getData()]), $count];
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
					$cause = $e->getPrevious()?->getPrevious();
					self::assertInstanceOf(PacketDecodeException::class, $cause);
					self::assertSame([$count, ItemStackRequest::MAX_ACTIONS], [$cause->observed, $cause->limit]);
				}
			}
		}
		Pulse::endTick();
		Pulse::stop();
		$data = $this->network($capture);
		self::assertSame(array_fill(0, 8, "packet.handler_validation"), array_column($data["events"], 4));
		self::assertSame([61, 61, 100000, 100000, 61, 61, 100000, 100000], array_column($data["events"], 5));
		self::assertSame(array_fill(0, 8, ItemStackRequest::MAX_ACTIONS), array_column($data["events"], 6));
		self::assertSame(array_merge(...array_fill(0, 4, [ItemStackRequestPacket::NETWORK_ID, PlayerAuthInputPacket::NETWORK_ID])), array_column($data["events"], 3));
		self::assertSame(array_fill(0, 8, "reject_batch"), array_column($data["events"], 7));
		self::assertSame(array_fill(0, 8, 1), array_column($data["events"], 1));
		self::assertSame(array_fill(0, 8, 1), array_column($data["events"], 2));
		self::assertSame([8, 0, 8, 0], [$data["windows"][0][3], $data["windows"][0][6], $data["windows"][0][7], $data["windows"][0][8]]);
		PulseReport::decode($capture->getReport()->encode());
	}

	public function testItemStackRequestBoundariesAndMalformedPrefixes() : void{
		$session = $this->session();
		$this->actions($session, [ItemStackRequestPacket::NETWORK_ID]);
		$handler = new class extends PacketHandler{
			public int $calls = 0;
			public function handleItemStackRequest(ItemStackRequestPacket $packet) : bool{
				++$this->calls;
				TestCase::assertSame([2, 3], [$packet->senderSubId, $packet->recipientSubId]);
				foreach($packet->getRequests() as $request){ TestCase::assertSame(["filter"], $request->getFilterStrings()); }
				return true;
			}
		};
		$this->handler($session, $handler);
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
			try{ $session->handleDataPacket(ItemStackRequestPacket::NETWORK_ID, $buffer); self::fail("Malformed prefix accepted"); }catch(PacketHandlingException){}
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
				$this->actions($session, [$class::NETWORK_ID]);
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
		$packet = ItemStackRequestPacket::create(array_fill(0, InGamePacketHandler::MAX_ITEM_STACK_REQUESTS + 1, new ItemStackRequest(0, [], [], 0)));
		$this->expectException(PacketHandlingException::class);
		$handler->handleItemStackRequest($packet);
	}

	public function testInventoryHandlerStillLimitsAlreadyDecodedPackets() : void{
		$handler = (new \ReflectionClass(InGamePacketHandler::class))->newInstanceWithoutConstructor();
		foreach([
			InventoryTransactionPacket::create(0, null, NormalTransactionData::new(array_fill(0, 51, new NetworkInventoryAction()))),
			InventoryTransactionPacket::create(0, array_fill(0, 11, new InventoryTransactionChangedSlotsHack(0, [])), NormalTransactionData::new([]))
		] as $packet){
			try{ $handler->handleInventoryTransaction($packet); self::fail("Handler accepted oversized inventory list"); }catch(PacketHandlingException $e){
				self::assertSame($packet->requestChangedSlots === null ? "Too many actions in inventory transaction" : "Too many slot sync requests in inventory transaction", $e->getMessage());
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
			try{ $session->handleDataPacket(RequestNetworkSettingsPacket::NETWORK_ID, $this->packet()); self::fail("Unrelated handler failure hidden"); }catch(\InvalidArgumentException $e){ self::assertSame("handler failure", $e->getMessage()); }
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

	public function testNestedCollectionsRejectWithPulseEvidenceOnBothReceivePaths() : void{
		$session = $this->getMockBuilder(NetworkSession::class)->disableOriginalConstructor()->onlyMethods(["getIp", "getDisplayName", "disconnectWithError"])->getMock();
		$session->method("getIp")->willReturn("192.0.2.1");
		$session->method("getDisplayName")->willReturn("private-name");
		$this->session($session);
		$this->actions($session, [InventoryTransactionPacket::NETWORK_ID, PlayerAuthInputPacket::NETWORK_ID, ItemStackRequestPacket::NETWORK_ID]);
		$handler = new class extends PacketHandler{
			/** @var array<string, int> */
			public array $calls = [];
			public function handleInventoryTransaction(InventoryTransactionPacket $packet) : bool{ $this->calls[__FUNCTION__] = ($this->calls[__FUNCTION__] ?? 0) + 1; return true; }
			public function handlePlayerAuthInput(PlayerAuthInputPacket $packet) : bool{ $this->calls[__FUNCTION__] = ($this->calls[__FUNCTION__] ?? 0) + 1; return true; }
			public function handleItemStackRequest(ItemStackRequestPacket $packet) : bool{ $this->calls[__FUNCTION__] = ($this->calls[__FUNCTION__] ?? 0) + 1; return true; }
		};
		$this->handler($session, $handler);
		$buffers = [];
		$observed = [];
		$limits = [];
		$ids = [];
		foreach([["containers", InventoryTransactionChangedSlotsHack::MAX_CHANGED_CONTAINERS], ["filters", ItemStackRequest::MAX_FILTER_STRINGS], ["ingredients", CraftRecipeAutoStackRequestAction::MAX_INGREDIENTS], ["results", DeprecatedCraftingResultsStackRequestAction::MAX_RESULTS]] as [$kind, $limit]){
			foreach([1, $limit, $limit + 1, 100000] as $count){
				$interaction = null;
				$request = null;
				if($kind === "containers"){
					$slots = array_fill(0, $count, new InventoryTransactionChangedSlotsHack(2, [255, 0, 1]));
					$transaction = UseItemTransactionData::new([], 0, TriggerType::PLAYER_INPUT, new BlockPosition(0, 0, 0), 0, 0, HandSlot::MAINHAND, ItemStackWrapper::legacy(ItemStack::null()), new Vector3(1, 2, 3), new Vector3(0, 0, 0), 0, PredictedResult::SUCCESS, 0);
					$interaction = new ItemInteractionData(-7, $slots, $transaction);
					$standalone = InventoryTransactionPacket::create(-7, $slots, NormalTransactionData::new([]));
				}else{
					$actions = match($kind){
						"ingredients" => [new CraftRecipeAutoStackRequestAction(7, 2, array_fill(0, $count, new RecipeIngredient(null, 1)))],
						"results" => [new DeprecatedCraftingResultsStackRequestAction(array_fill(0, $count, new DeprecatedCraftingResultsEntry(null, 1, 4, "")), 2)],
						default => [new LabTableCombineStackRequestAction()]
					};
					$request = new ItemStackRequest(-7, $actions, $kind === "filters" ? array_fill(0, $count, "") : [], 0);
					$standalone = ItemStackRequestPacket::create([$request]);
				}
				$movement = PlayerAuthInputPacket::create(new Vector3(1, 2, 3), 4, 5, 6, 0, 0, new BitSet(PlayerAuthInputFlags::NUMBER_OF_FLAGS), InputMode::MOUSE_KEYBOARD, PlayMode::NORMAL, InteractionMode::TOUCH, new Vector2(0, 0), 123, new Vector3(0, 0, 0), $interaction, $request, null, null, 0, 0, new Vector3(0, 0, 0), new Vector2(0, 0));
				foreach([$standalone, $movement] as $packet){
					$writer = new ByteBufferWriter();
					$packet->encode($writer);
					$buffer = $this->batch([$writer->getData()]);
					if($count <= $limit){ $session->handleEncoded($buffer); }else{
						$buffers[] = $buffer;
						$observed[] = $count; $observed[] = null;
						$limits[] = $limit; $limits[] = null;
						$ids[] = $packet->pid(); $ids[] = null;
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
		self::assertSame(array_merge(...array_fill(0, count($buffers) * 2, ["packet.handler_validation", "packet.bad_packet_disconnect"])), array_column($data["events"], 4));
		self::assertSame(array_merge($observed, $observed), array_column($data["events"], 5));
		self::assertSame(array_merge($limits, $limits), array_column($data["events"], 6));
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
