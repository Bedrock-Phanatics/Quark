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
use quark\network\mcpe\protocol\Packets;
use quark\network\PacketHandlingException;
use quark\player\Player;
use quark\world\World;
use function array_fill;
use function str_repeat;

/**
 * @phpstan-import-type PlayerAuthInputFields from Packets
 */
final class InGamePacketHandlerTest extends TestCase{

	private static function handler() : InGamePacketHandler{
		return (new \ReflectionClass(InGamePacketHandler::class))->newInstanceWithoutConstructor();
	}

	/**
	 * @phpstan-return PlayerAuthInputFields
	 */
	private static function authInput() : array{
		$zero2 = ["x" => 0.0, "y" => 0.0];
		$zero3 = ["x" => 0.0, "y" => 0.0, "z" => 0.0];
		return [
			"player_rotation" => $zero2,
			"position" => $zero3,
			"move_vector" => $zero2,
			"player_head_rotation" => 0.0,
			"input_data" => [],
			"input_mode" => 1,
			"play_mode" => 0,
			"new_interaction_model" => 0,
			"interact_rotation" => $zero2,
			"client_tick" => 0,
			"pos_delta" => $zero3,
			"item_use_transaction" => null,
			"item_stack_request" => null,
			"player_block_actions" => null,
			"vehicle_rotation" => null,
			"client_predicted_vehicle" => null,
			"analog_move_vector" => $zero2,
			"camera_orientation" => $zero3,
			"raw_move_vector" => $zero2,
		];
	}

	/**
	 * @return \Generator<string, array{PlayerAuthInputFields, string}>
	 */
	public static function invalidAuthInputProvider() : \Generator{
		foreach(["negative flag" => [-1], "unknown flag" => [PlayerAuthInputFlags::NUMBER_OF_FLAGS], "duplicate flag" => [3, 3]] as $name => $flags){
			$packet = self::authInput();
			$packet["input_data"] = $flags;
			yield $name => [$packet, $name === "duplicate flag" ? "Duplicate input flag 3" : "Unknown input flag " . $flags[0]];
		}
		$packet = self::authInput();
		$packet["vehicle_rotation"] = ["x" => 0.0, "y" => 0.0];
		yield "vehicle rotation alone" => [$packet, "Vehicle rotation and actor unique ID must both be present or both be absent"];
		$packet = self::authInput();
		$packet["client_predicted_vehicle"] = 5;
		yield "vehicle id alone" => [$packet, "Vehicle rotation and actor unique ID must both be present or both be absent"];
	}

	/**
	 * @dataProvider invalidAuthInputProvider
	 * @phpstan-param PlayerAuthInputFields $packet
	 */
	public function testRejectsInvalidAuthInput(array $packet, string $message) : void{
		$this->expectException(PacketHandlingException::class);
		$this->expectExceptionMessage($message);
		self::handler()->handlePlayerAuthInput($packet);
	}

	/**
	 * @return Player&MockObject
	 */
	private function player() : Player{
		$player = $this->createMock(Player::class);
		//keeps the destructor from closing a player that was never set up
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new \PrefixedLogger($this->createMock(\Logger::class), "test"));
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
		$packet["player_block_actions"] = [];
		for($i = 0; $i < 100; ++$i){
			[$x, $y, $z] = $position($i);
			$packet["player_block_actions"][] = ["player_action_type" => PlayerAction::PREDICT_DESTROY_BLOCK, "position" => ["x" => $x, "y" => $y, "z" => $z], "facing" => Facing::UP];
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
	 * @return \Generator<string, array{array<string, mixed>}>
	 */
	public static function farBookPageProvider() : \Generator{
		yield "replace" => [["replace_page" => ["page_index" => Limits::INT32_MAX, "page_text" => "x"]]];
		yield "swap" => [["swap_pages" => ["page_index" => 0, "swap_with_index" => Limits::INT32_MAX]]];
		yield "just past the client's last page" => [["replace_page" => ["page_index" => 50, "page_text" => "x"]]];
	}

	/**
	 * @dataProvider farBookPageProvider
	 * @param array<string, mixed> $operation
	 */
	public function testRejectsBookPagesPastTheLastOneBeforeEditing(array $operation) : void{
		$book = VanillaItems::WRITABLE_BOOK();
		$inventory = $this->createMock(PlayerInventory::class);
		$inventory->method("slotExists")->willReturn(true);
		$inventory->method("getItem")->willReturn($book);
		$player = $this->player();
		$player->method("getInventory")->willReturn($inventory);

		try{
			/** @phpstan-ignore argument.type */
			$this->handlerFor($player)->handleBookEdit(["book_slot" => 0, "operation" => $operation]);
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

	public function testLimitsAuthInputTransactionActionsLikeInventoryTransactions() : void{
		$packet = self::authInput();
		$packet["item_use_transaction"] = ["legacy_request_id" => 0, "legacy_set_item_slots" => null, "item_use_transaction" => ["actions" => array_fill(0, InGamePacketHandler::MAX_INVENTORY_TRANSACTION_ACTIONS + 1, [])]];
		try{
			/** @phpstan-ignore argument.type */
			self::handler()->handlePlayerAuthInput($packet);
			self::fail("Oversized transaction accepted");
		}catch(PacketHandlingException $e){
			self::assertSame(InGamePacketHandler::MAX_INVENTORY_TRANSACTION_ACTIONS, $e->limit);
		}
	}

	public function testRejectsOversizedBlockActorDataBeforeParsing() : void{
		try{
			self::handler()->handleBlockActorData(["block_position" => ["x" => 0, "y" => 0, "z" => 0], "actor_data_tags" => str_repeat("\0", 16 * 1024 + 1)]);
			self::fail("Oversized block actor data accepted");
		}catch(PacketHandlingException $e){
			self::assertSame([16 * 1024 + 1, 16 * 1024], [$e->observed, $e->limit]);
		}
	}

	public function testLimitsInputFlagCount() : void{
		$packet = self::authInput();
		$packet["input_data"] = [];
		for($i = 0; $i <= PlayerAuthInputFlags::NUMBER_OF_FLAGS; ++$i){
			$packet["input_data"][] = $i % PlayerAuthInputFlags::NUMBER_OF_FLAGS;
		}
		try{
			self::handler()->handlePlayerAuthInput($packet);
			self::fail("Oversized input flag list accepted");
		}catch(PacketHandlingException $e){
			self::assertSame([PlayerAuthInputFlags::NUMBER_OF_FLAGS + 1, PlayerAuthInputFlags::NUMBER_OF_FLAGS], [$e->observed, $e->limit]);
		}
	}
}
