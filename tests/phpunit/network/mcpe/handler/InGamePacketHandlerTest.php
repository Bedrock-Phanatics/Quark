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
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use quark\network\mcpe\protocol\Packets;
use quark\network\PacketHandlingException;

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
