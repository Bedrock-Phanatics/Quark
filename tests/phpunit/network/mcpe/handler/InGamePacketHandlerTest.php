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

namespace quark\network\mcpe\handler;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use pmmp\encoding\ByteBufferWriter;
use pmmp\encoding\VarInt;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\protocol\BlockActorDataPacket;
use pocketmine\network\mcpe\protocol\BookEditPacket;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\serializer\BitSet;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\InputMode;
use pocketmine\network\mcpe\protocol\types\InteractionMode;
use pocketmine\network\mcpe\protocol\types\inventory\HandSlot;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\inventory\NetworkInventoryAction;
use pocketmine\network\mcpe\protocol\types\inventory\PredictedResult;
use pocketmine\network\mcpe\protocol\types\inventory\TriggerType;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\ItemInteractionData;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use pocketmine\network\mcpe\protocol\types\PlayerBlockAction;
use pocketmine\network\mcpe\protocol\types\PlayMode;
use pocketmine\utils\Limits;
use quark\entity\Entity;
use quark\entity\Location;
use quark\inventory\SimpleInventory;
use quark\item\VanillaItems;
use quark\item\WritableBookBase;
use quark\math\Facing;
use quark\math\Vector2;
use quark\math\Vector3;
use quark\network\mcpe\InventoryManager;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\EncodedPacket;
use quark\network\PacketHandlingException;
use quark\player\Player;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;
use quark\world\World;
use function array_fill;
use function count;
use function pack;
use function range;
use function str_repeat;
use function strlen;
use function substr;
use const NAN;

final class InGamePacketHandlerTest extends TestCase{

	protected function setUp() : void{
		Pulse::reset();
		PulseZones::init();
	}

	protected function tearDown() : void{
		Pulse::reset();
	}

	private static function handler() : InGamePacketHandler{
		return new \ReflectionClass(InGamePacketHandler::class)->newInstanceWithoutConstructor();
	}

	/**
	 * @param list<int>                    $inputFlags
	 * @param list<PlayerBlockAction>|null $blockActions
	 */
	private static function authInput(array $inputFlags = [], Vector3 $position = new Vector3(0.0, 0.0, 0.0), ?ItemInteractionData $itemInteraction = null, ?array $blockActions = null) : PlayerAuthInputPacket{
		$flags = new BitSet(PlayerAuthInputFlags::NUMBER_OF_FLAGS);
		foreach($inputFlags as $flag){
			$flags->set($flag, true);
		}
		$zero2 = new Vector2(0.0, 0.0);
		$zero3 = new Vector3(0.0, 0.0, 0.0);
		return PlayerAuthInputPacket::create($position, 0.0, 0.0, 0.0, 0.0, 0.0, $flags, InputMode::MOUSE_KEYBOARD, PlayMode::NORMAL, InteractionMode::TOUCH, $zero2, 0, $zero3, $itemInteraction, null, $blockActions, null, 0.0, 0.0, $zero3, $zero2);
	}

	/**
	 * A session that decodes packets for the handler, so checks the decoder makes can be seen as the session sees them.
	 */
	private function session(PacketHandler $handler) : NetworkSession{
		$session = new \ReflectionClass(NetworkSession::class)->newInstanceWithoutConstructor();
		$set = static fn(string $property, mixed $value) => new \ReflectionProperty(NetworkSession::class, $property)->setValue($session, $value);
		$set("logger", new \PrefixedLogger($this->createMock(\Logger::class), "test"));
		$set("handler", $handler);
		$set("handlerActions", PacketHandlerInspector::getHandlerActions($handler));
		return $session;
	}

	/**
	 * An encoded auth input with no flags or vehicle, with its flag list and vehicle fields swapped for raw bytes.
	 */
	private static function authInputBytes(string $flags, string $vehicle = "\x00\x00") : string{
		$bytes = EncodedPacket::encode(self::authInput());
		//2 byte header, 8 floats before the flag list, and 28 bytes of floats after the vehicle
		return substr($bytes, 0, 34) . $flags . substr($bytes, 35, -30) . $vehicle . substr($bytes, -28);
	}

	private static function flagList(int ...$flags) : string{
		$out = new ByteBufferWriter();
		VarInt::writeUnsignedInt($out, count($flags));
		foreach($flags as $flag){
			VarInt::writeSignedInt($out, $flag);
		}
		return $out->getData();
	}

	/**
	 * @return \Generator<string, array{string, string}>
	 */
	public static function invalidAuthInputProvider() : \Generator{
		yield "negative flag" => [self::authInputBytes(self::flagList(-1)), "Index out of bounds"];
		yield "unknown flag" => [self::authInputBytes(self::flagList(PlayerAuthInputFlags::NUMBER_OF_FLAGS)), "Index out of bounds"];
		yield "duplicate flag" => [self::authInputBytes(self::flagList(3, 3)), "Duplicate input flag 3"];
		yield "more flags than exist" => [self::authInputBytes(self::flagList(...[...range(0, PlayerAuthInputFlags::NUMBER_OF_FLAGS - 1), 0])), "Duplicate input flag 0"];
		$message = "Vehicle rotation and actor unique ID must both be present or both be absent";
		yield "vehicle rotation alone" => [self::authInputBytes(self::flagList(), "\x01" . pack("g2", 0.0, 0.0) . "\x00"), $message];
		yield "vehicle id alone" => [self::authInputBytes(self::flagList(), "\x00\x01\x0a"), $message];
	}

	/**
	 * @dataProvider invalidAuthInputProvider
	 */
	public function testRejectsInvalidAuthInput(string $bytes, string $message) : void{
		self::assertInstanceOf(PlayerAuthInputPacket::class, EncodedPacket::fromBytes(self::authInputBytes(self::flagList()))->getPacket());
		$this->expectException(PacketHandlingException::class);
		$this->expectExceptionMessage($message);
		$this->session(self::handler())->handleDataPacket($bytes);
	}

	/**
	 * @return Player&MockObject
	 */
	private function player() : Player{
		$player = $this->createMock(Player::class);
		new \ReflectionProperty(Entity::class, "closed")->setValue($player, true);
		new \ReflectionProperty(Player::class, "logger")->setValue($player, new \PrefixedLogger($this->createMock(\Logger::class), "test"));
		return $player;
	}

	private function handlerFor(Player $player) : InGamePacketHandler{
		return new InGamePacketHandler($player, $this->createMock(NetworkSession::class), $this->createMock(InventoryManager::class));
	}

	/**
	 * @phpstan-param \Closure(int) : array{int, int, int} $position
	 * @return list<Vector3>
	 */
	private function syncedBlocksAfterFailedBreaks(\Closure $position) : array{
		$synced = [];
		$world = $this->createMock(World::class);
		$world->expects(self::once())->method("createBlockUpdatePackets")->willReturnCallback(function(array $blocks) use (&$synced) : array{
			$synced = $blocks;
			return [];
		});
		$player = $this->player();
		$player->method("getLocation")->willReturn(new Location(0, 0, 0, null, 0, 0));
		$player->method("getWorld")->willReturn($world);
		$player->method("breakBlock")->willReturn(false);
		$handler = $this->handlerFor($player);

		$actions = [];
		for($i = 0; $i < 100; ++$i){
			$actions[] = new PlayerBlockAction(PlayerAction::PREDICT_DESTROY_BLOCK, new BlockPosition(...$position($i)), Facing::UP);
		}
		$handler->handlePlayerAuthInput(self::authInput(blockActions: $actions));
		/** @var list<Vector3> $synced */
		return $synced;
	}

	public function testCoalescesBlockSyncsOfOneAuthInput() : void{
		self::assertCount(12, $this->syncedBlocksAfterFailedBreaks(static fn(int $i) : array => [1, 2, 3]));
	}

	public function testLimitsBlockSyncsOfOneAuthInput() : void{
		self::assertCount(128, $this->syncedBlocksAfterFailedBreaks(static fn(int $i) : array => [$i * 3 - 150, 2, 3]));
	}

	private static function bookEdit(int $type, int $pageNumber, int $secondaryPageNumber = 0) : BookEditPacket{
		$packet = new BookEditPacket();
		$packet->type = $type;
		$packet->inventorySlot = 0;
		$packet->pageNumber = $pageNumber;
		$packet->secondaryPageNumber = $secondaryPageNumber;
		$packet->text = "x";
		$packet->photoName = "";
		return $packet;
	}

	/**
	 * @return \Generator<string, array{BookEditPacket}>
	 */
	public static function farBookPageProvider() : \Generator{
		yield "replace" => [self::bookEdit(BookEditPacket::TYPE_REPLACE_PAGE, Limits::INT32_MAX)];
		yield "swap" => [self::bookEdit(BookEditPacket::TYPE_SWAP_PAGES, 0, Limits::INT32_MAX)];
		yield "just past the client's last page" => [self::bookEdit(BookEditPacket::TYPE_REPLACE_PAGE, 50)];
	}

	/**
	 * @dataProvider farBookPageProvider
	 */
	public function testRejectsBookPagesPastTheLastOneBeforeEditing(BookEditPacket $packet) : void{
		$book = VanillaItems::WRITABLE_BOOK();
		$inventory = $this->createMock(SimpleInventory::class);
		$inventory->method("slotExists")->willReturn(true);
		$inventory->method("getItem")->willReturn($book);
		$player = $this->player();
		$player->method("getInventory")->willReturn($inventory);

		try{
			$this->handlerFor($player)->handleBookEdit($packet);
			self::fail("Far page accepted");
		}catch(PacketHandlingException $e){
			self::assertSame(49, $e->limit);
		}
		self::assertSame([], $book->getPages());
	}

	public function testBooksCannotGrowPastTheirPageCap() : void{
		$this->expectException(\InvalidArgumentException::class);
		VanillaItems::WRITABLE_BOOK()->addPage(WritableBookBase::MAX_PAGES);
	}

	public function testRepeatedInputFlagsAreAppliedOnce() : void{
		$player = $this->player();
		$player->method("getLocation")->willReturn(new Location(0, 0, 0, null, 0, 0));
		$player->expects(self::exactly(3))->method("toggleSneak")->willReturn(true);
		$handler = $this->handlerFor($player);
		foreach([[PlayerAuthInputFlags::START_SNEAKING], [PlayerAuthInputFlags::START_SNEAKING], [], [], [PlayerAuthInputFlags::START_SNEAKING]] as $flags){
			self::assertTrue($handler->handlePlayerAuthInput(self::authInput($flags)));
		}
	}

	public function testRejectsNonFiniteMovement() : void{
		$player = $this->player();
		$player->expects(self::never())->method("handleMovement");
		self::assertFalse($this->handlerFor($player)->handlePlayerAuthInput(self::authInput(position: new Vector3(0.0, NAN, 0.0))));
	}

	public function testLimitsAuthInputTransactionActionsLikeInventoryTransactions() : void{
		$item = ItemStackWrapper::legacy(ItemStack::null());
		$action = new NetworkInventoryAction();
		$action->sourceType = NetworkInventoryAction::SOURCE_CONTAINER;
		$action->windowId = 0;
		$action->inventorySlot = 0;
		$action->oldItem = $item;
		$action->newItem = $item;
		$zero = new Vector3(0, 0, 0);
		$transaction = UseItemTransactionData::new(
			array_fill(0, InGamePacketHandler::MAX_INVENTORY_TRANSACTION_ACTIONS + 1, $action), UseItemTransactionData::ACTION_CLICK_AIR, TriggerType::PLAYER_INPUT,
			new BlockPosition(0, 0, 0), 0, 0, HandSlot::MAINHAND, $item, $zero, $zero, 0, PredictedResult::FAILURE, 0
		);
		try{
			self::handler()->handlePlayerAuthInput(self::authInput(itemInteraction: new ItemInteractionData(0, null, $transaction)));
			self::fail("Oversized transaction accepted");
		}catch(PacketHandlingException $e){
			self::assertSame(InGamePacketHandler::MAX_INVENTORY_TRANSACTION_ACTIONS, $e->limit);
		}
	}

	public function testRejectsOversizedBlockActorDataBeforeParsing() : void{
		$nbt = CompoundTag::create()->setString("text", str_repeat("x", 16 * 1024));
		$bytes = EncodedPacket::encode(BlockActorDataPacket::create(new BlockPosition(0, 0, 0), new CacheableNbt($nbt)));
		self::assertGreaterThan(16 * 1024, strlen($bytes));
		try{
			$this->session(self::handler())->handleDataPacket($bytes);
			self::fail("Oversized block actor data accepted");
		}catch(PacketHandlingException $e){
			self::assertSame([strlen($bytes), 16 * 1024], [$e->observed, $e->limit]);
		}
	}
}
