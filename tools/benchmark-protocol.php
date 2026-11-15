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

namespace quark\tools\benchmark_protocol;

use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use quark\math\Vector3;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\EncodedPacket;
use function array_map;
use function bin2hex;
use function dirname;
use function hex2bin;
use function hrtime;
use function intdiv;
use function json_encode;
use function max;
use function memory_get_peak_usage;
use function memory_get_usage;
use function memory_reset_peak_usage;
use function range;
use function round;
use function sort;
use function strlen;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const PHP_EOL;

require dirname(__DIR__) . '/vendor/autoload.php';

//serverbound packets as BedrockProtocol encoded them: walking and sprinting auth input, clicking a block with a named
//sword, and taking then placing a stack
const AUTH_INPUT = "900100004841000087430000c942713d83420000a2c1000000000000803f00008743031428320100020000484100008743c0c407000000000ad7a3bd295c8f3e0000000000000000000000803f00000000000000000000803f000000000000803f";
const USE_ITEM = "1e000002000001148001050100003e01010000000028ffff010a00000a0700646973706c61790804004e616d6505006e616d65640000000000000000000000002841713d8342000020c00000003f0000803f0000003f000100";
const STACK_REQUEST = "93010205010000201c0000050000003b000000000000000000000009010101203b0000000000001d000c000000000000000000";

/**
 * Runs $fn in seven timed rounds after a warm-up and returns the median time and the transient memory of one call.
 *
 * @phpstan-param \Closure() : mixed $fn
 * @phpstan-return array{us: float, alloc_kib: float}
 */
function measure(int $iterations, \Closure $fn) : array{
	for($i = max(1, intdiv($iterations, 10)); $i > 0; --$i){
		$fn();
	}
	$rounds = [];
	for($round = 0; $round < 7; ++$round){
		$start = hrtime(true);
		for($i = 0; $i < $iterations; ++$i){
			$fn();
		}
		$rounds[] = (hrtime(true) - $start) / $iterations / 1000;
	}
	sort($rounds);

	$base = memory_get_usage();
	memory_reset_peak_usage();
	$result = $fn();
	$alloc = memory_get_peak_usage() - $base;
	unset($result);
	return ["us" => round($rounds[3], 3), "alloc_kib" => round($alloc / 1024, 1)];
}

/**
 * Times receiving one packet: from its bytes to the value a handler is given, after checking it round trips.
 *
 * @return mixed[]
 */
function inbound(string $label, string $hex, int $iterations) : array{
	$buffer = hex2bin($hex);
	if($buffer === false){
		throw new \RuntimeException("$label: invalid hex");
	}
	$decode = static fn() : Packet => EncodedPacket::fromBytes($buffer)->getPacket();
	$encoded = EncodedPacket::encode($decode());
	if($encoded !== $buffer){
		throw new \RuntimeException("$label: decoding and encoding again changed the bytes: " . bin2hex($encoded));
	}
	return ["bytes" => strlen($buffer)] + measure($iterations, $decode);
}

function mobMetadata(EntityMetadataCollection $metadata, int $id) : EntityMetadataCollection{
	$metadata->setGenericFlag(EntityMetadataFlags::AFFECTED_BY_GRAVITY, true);
	$metadata->setGenericFlag(EntityMetadataFlags::HAS_COLLISION, true);
	$metadata->setGenericFlag(EntityMetadataFlags::CAN_CLIMB, true);
	$metadata->setGenericFlag(EntityMetadataFlags::BREATHING, true);
	$metadata->setShort(EntityMetadataProperties::AIR, 300);
	$metadata->setShort(EntityMetadataProperties::MAX_AIR, 300);
	$metadata->setFloat(EntityMetadataProperties::BOUNDING_BOX_WIDTH, 0.6);
	$metadata->setFloat(EntityMetadataProperties::BOUNDING_BOX_HEIGHT, 1.95);
	$metadata->setFloat(EntityMetadataProperties::SCALE, 1.0);
	$metadata->setLong(EntityMetadataProperties::LEAD_HOLDER_EID, -1);
	$metadata->setString(EntityMetadataProperties::NAMETAG, "Zombie $id");
	$metadata->setInt(EntityMetadataProperties::VARIANT, 0);
	$metadata->setByte(EntityMetadataProperties::COLOR, 0);
	return $metadata;
}

/**
 * Times building, encoding and batching what a viewer receives from a group of mobs and a few sounds. With $spawn
 * every mob sends all of its metadata, as on spawning, otherwise the mobs move and three of them send one changed
 * property.
 *
 * @return mixed[]
 */
function outboundTick(int $mobs, bool $spawn, int $iterations) : array{
	$positions = array_map(fn(int $i) => new Vector3(100.5 + $i, 64.0, -20.25 - $i), range(0, $mobs - 1));
	$metadata = array_map(function(int $i) : EntityMetadataCollection{
		$metadata = mobMetadata(new EntityMetadataCollection(), $i);
		$metadata->clearDirtyProperties();
		return $metadata;
	}, range(0, $mobs - 1));
	$tick = 0;
	$batch = static function() use ($positions, $spawn, $metadata, &$tick) : string{
		$packets = [];
		++$tick;
		foreach($positions as $i => $position){
			$packets[] = EncodedPacket::encode(MoveActorAbsolutePacket::create($i + 10, $position, 12.0, 90.0 + $i, 90.0 + $i, MoveActorAbsolutePacket::FLAG_GROUND));
			if($spawn){
				$data = $metadata[$i]->getAll();
			}elseif($i < 3){
				$metadata[$i]->setShort(EntityMetadataProperties::AIR, $tick % 300);
				$data = $metadata[$i]->getDirty();
				$metadata[$i]->clearDirtyProperties();
			}else{
				continue;
			}
			$packets[] = EncodedPacket::encode(SetActorDataPacket::create($i + 10, $data, new PropertySyncData([], []), 0));
		}
		for($i = 0; $i < 3; ++$i){
			$packets[] = EncodedPacket::encode(LevelSoundEventPacket::nonActorSound(LevelSoundEvent::STEP, $positions[$i], false));
		}
		return NetworkSession::encodeBatch($packets);
	};
	return ["bytes" => strlen($batch())] + measure($iterations, $batch);
}

echo json_encode([
	"inbound" => [
		"player_auth_input" => inbound("player_auth_input", AUTH_INPUT, 20000),
		"inventory_transaction_use_item" => inbound("inventory_transaction", USE_ITEM, 20000),
		"item_stack_request_take_place" => inbound("item_stack_request", STACK_REQUEST, 20000),
	],
	"outbound" => [
		"spawn_20_mobs" => outboundTick(20, true, 500),
		"tick_20_moving_mobs" => outboundTick(20, false, 2000),
	],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
