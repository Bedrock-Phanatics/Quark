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
use pocketmine\math\Facing;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use pocketmine\utils\Limits;
use quark\entity\Entity;
use quark\entity\Location;
use quark\inventory\PlayerInventory;
use quark\item\VanillaItems;
use quark\item\WritableBookBase;
use quark\network\mcpe\InventoryManager;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\packet\BlockActorDataPacket;
use quark\network\mcpe\protocol\packet\BookEditPacket;
use quark\network\mcpe\protocol\packet\PlayerAuthInputPacket;
use quark\network\mcpe\protocol\types\BookEditActionReplacePage;
use quark\network\mcpe\protocol\types\BookEditActionSwapPages;
use quark\network\mcpe\protocol\types\InventoryAction;
use quark\network\mcpe\protocol\types\InventorySource;
use quark\network\mcpe\protocol\types\ItemUseInventoryTransaction;
use quark\network\mcpe\protocol\types\NetworkItemStackDescriptor;
use quark\network\mcpe\protocol\types\PackedItemUseLegacyInventoryTransaction;
use quark\network\mcpe\protocol\types\PlayerBlockAction;
use quark\network\PacketHandlingException;
use quark\player\Player;
use quark\world\World;
use function array_fill;
use function str_repeat;
use const NAN;

final class InGamePacketHandlerTest extends TestCase{

	private static function handler() : InGamePacketHandler{
		return new \ReflectionClass(InGamePacketHandler::class)->newInstanceWithoutConstructor();
	}

	/**
	 * @param list<int> $inputData
	 */
	private static function authInput(array $inputData = []) : PlayerAuthInputPacket{
		return new PlayerAuthInputPacket(
			playerRotation: new Vector2(0.0, 0.0),
			position: new Vector3(0.0, 0.0, 0.0),
			moveVector: new Vector2(0.0, 0.0),
			playerHeadRotation: 0.0,
			inputData: $inputData,
			inputMode: 1,
			playMode: 0,
			newInteractionModel: 0,
			interactRotation: new Vector2(0.0, 0.0),
			clientTick: 0,
			posDelta: new Vector3(0.0, 0.0, 0.0),
			itemUseTransaction: null,
			itemStackRequest: null,
			playerBlockActions: null,
			vehicleRotation: null,
			clientPredictedVehicle: null,
			analogMoveVector: new Vector2(0.0, 0.0),
			cameraOrientation: new Vector3(0.0, 0.0, 0.0),
			rawMoveVector: new Vector2(0.0, 0.0),
		);
	}

	/**
	 * @return \Generator<string, array{PlayerAuthInputPacket, string}>
	 */
	public static function invalidAuthInputProvider() : \Generator{
		foreach(["negative flag" => [-1], "unknown flag" => [PlayerAuthInputFlags::NUMBER_OF_FLAGS], "duplicate flag" => [3, 3]] as $name => $flags){
			yield $name => [self::authInput($flags), $name === "duplicate flag" ? "Duplicate input flag 3" : "Unknown input flag " . $flags[0]];
		}
		$packet = self::authInput();
		$packet->vehicleRotation = new Vector2(0.0, 0.0);
		yield "vehicle rotation alone" => [$packet, "Vehicle rotation and actor unique ID must both be present or both be absent"];
		$packet = self::authInput();
		$packet->clientPredictedVehicle = 5;
		yield "vehicle id alone" => [$packet, "Vehicle rotation and actor unique ID must both be present or both be absent"];
	}

	/**
	 * @dataProvider invalidAuthInputProvider
	 */
	public function testRejectsInvalidAuthInput(PlayerAuthInputPacket $packet, string $message) : void{
		$this->expectException(PacketHandlingException::class);
		$this->expectExceptionMessage($message);
		self::handler()->handlePlayerAuthInput($packet);
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

		$packet = self::authInput();
		$packet->playerBlockActions = [];
		for($i = 0; $i < 100; ++$i){
			$packet->playerBlockActions[] = new PlayerBlockAction(PlayerAction::PREDICT_DESTROY_BLOCK, new Vector3(...$position($i)), Facing::UP);
		}
		$handler->handlePlayerAuthInput($packet);
		/** @var list<Vector3> $synced */
		return $synced;
	}

	public function testCoalescesBlockSyncsOfOneAuthInput() : void{
		self::assertCount(12, $this->syncedBlocksAfterFailedBreaks(static fn(int $i) : array => [1, 2, 3]));
	}

	public function testLimitsBlockSyncsOfOneAuthInput() : void{
		self::assertCount(128, $this->syncedBlocksAfterFailedBreaks(static fn(int $i) : array => [$i * 3 - 150, 2, 3]));
	}

	/**
	 * @return \Generator<string, array{BookEditPacket}>
	 */
	public static function farBookPageProvider() : \Generator{
		yield "replace" => [new BookEditPacket(0, ["replace_page" => new BookEditActionReplacePage(Limits::INT32_MAX, "x", "")])];
		yield "swap" => [new BookEditPacket(0, ["swap_pages" => new BookEditActionSwapPages(0, Limits::INT32_MAX)])];
		yield "just past the client's last page" => [new BookEditPacket(0, ["replace_page" => new BookEditActionReplacePage(50, "x", "")])];
	}

	/**
	 * @dataProvider farBookPageProvider
	 */
	public function testRejectsBookPagesPastTheLastOneBeforeEditing(BookEditPacket $packet) : void{
		$book = VanillaItems::WRITABLE_BOOK();
		$inventory = $this->createMock(PlayerInventory::class);
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
		$packet = self::authInput();
		$packet->position = new Vector3(0.0, NAN, 0.0);
		self::assertFalse($this->handlerFor($player)->handlePlayerAuthInput($packet));
	}

	public function testLimitsAuthInputTransactionActionsLikeInventoryTransactions() : void{
		$item = new NetworkItemStackDescriptor(0, 0, 0, null, 0, "");
		$action = new InventoryAction(new InventorySource(0), 0, $item, $item);
		$zero = new Vector3(0, 0, 0);
		$packet = self::authInput();
		$packet->itemUseTransaction = new PackedItemUseLegacyInventoryTransaction(0, null, new ItemUseInventoryTransaction(
			array_fill(0, InGamePacketHandler::MAX_INVENTORY_TRANSACTION_ACTIONS + 1, $action), 0, 0, $zero, 0, 0, 0, $item, $zero, $zero, 0, 0, 0
		));
		try{
			self::handler()->handlePlayerAuthInput($packet);
			self::fail("Oversized transaction accepted");
		}catch(PacketHandlingException $e){
			self::assertSame(InGamePacketHandler::MAX_INVENTORY_TRANSACTION_ACTIONS, $e->limit);
		}
	}

	public function testRejectsOversizedBlockActorDataBeforeParsing() : void{
		try{
			self::handler()->handleBlockActorData(new BlockActorDataPacket(new Vector3(0, 0, 0), str_repeat("\0", 16 * 1024 + 1)));
			self::fail("Oversized block actor data accepted");
		}catch(PacketHandlingException $e){
			self::assertSame([16 * 1024 + 1, 16 * 1024], [$e->observed, $e->limit]);
		}
	}

	public function testLimitsInputFlagCount() : void{
		$flags = [];
		for($i = 0; $i <= PlayerAuthInputFlags::NUMBER_OF_FLAGS; ++$i){
			$flags[] = $i % PlayerAuthInputFlags::NUMBER_OF_FLAGS;
		}
		try{
			self::handler()->handlePlayerAuthInput(self::authInput($flags));
			self::fail("Oversized input flag list accepted");
		}catch(PacketHandlingException $e){
			self::assertSame([PlayerAuthInputFlags::NUMBER_OF_FLAGS + 1, PlayerAuthInputFlags::NUMBER_OF_FLAGS], [$e->observed, $e->limit]);
		}
	}
}
