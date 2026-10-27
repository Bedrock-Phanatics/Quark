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

use PHPUnit\Framework\TestCase;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use quark\entity\Entity;
use quark\entity\Location;
use quark\network\mcpe\InventoryManager;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\Packets;
use quark\network\PacketHandlingException;
use quark\player\Player;
use quark\world\World;

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
		$player = $this->createMock(Player::class);
		$player->method("getLocation")->willReturn(new Location(0, 0, 0, null, 0, 0));
		$player->method("getWorld")->willReturn($world);
		$player->method("breakBlock")->willReturn(false);
		//keeps the destructor from closing a player that was never set up
		(new \ReflectionProperty(Entity::class, "closed"))->setValue($player, true);
		(new \ReflectionProperty(Player::class, "logger"))->setValue($player, new \PrefixedLogger($this->createMock(\Logger::class), "test"));
		$handler = new InGamePacketHandler($player, $this->createMock(NetworkSession::class), $this->createMock(InventoryManager::class));

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
