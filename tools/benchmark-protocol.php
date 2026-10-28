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

use Bedwire\Protocol;
use pmmp\encoding\ByteBufferReader;
use pmmp\encoding\ByteBufferWriter;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\ItemStackRequestPacket;
use pocketmine\network\mcpe\protocol\LevelSoundEventPacket;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\serializer\BitSet;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\network\mcpe\protocol\types\InputMode;
use pocketmine\network\mcpe\protocol\types\InteractionMode;
use pocketmine\network\mcpe\protocol\types\inventory\ContainerUIIds;
use pocketmine\network\mcpe\protocol\types\inventory\FullContainerName;
use pocketmine\network\mcpe\protocol\types\inventory\HandSlot;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\inventory\PredictedResult;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequest;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\ItemStackRequestSlotInfo;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\PlaceStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\stackrequest\TakeStackRequestAction;
use pocketmine\network\mcpe\protocol\types\inventory\TriggerType;
use pocketmine\network\mcpe\protocol\types\inventory\UseItemTransactionData;
use pocketmine\network\mcpe\protocol\types\LevelSoundEvent;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use pocketmine\network\mcpe\protocol\types\PlayMode;
use quark\item\VanillaItems;
use quark\network\mcpe\convert\TypeConverter;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\EncodedPacket;
use quark\network\mcpe\protocol\EntityMetadata;
use quark\network\mcpe\protocol\Fields;
use quark\network\mcpe\protocol\LevelPackets;
use quark\network\mcpe\protocol\Packet;
use quark\network\mcpe\protocol\packet\MoveActorAbsolutePacket as NetMoveActorAbsolutePacket;
use quark\network\mcpe\protocol\packet\SetActorDataPacket as NetSetActorDataPacket;
use quark\network\mcpe\protocol\ProtocolClasses;
use quark\network\mcpe\protocol\types\PropertySyncData as NetPropertySyncData;
use quark\pulse\internal\PulseZones;
use function array_map;
use function bin2hex;
use function dirname;
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

PulseZones::init();

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

function legacyEncode(DataPacket $packet) : string{
	$writer = new ByteBufferWriter();
	$packet->encode($writer);
	return $writer->getData();
}

/**
 * Times the BedrockProtocol path, the transitional adapter and the native path for one workload, after checking
 * that all three produce the same bytes. Each path is the timed work and a closure giving the bytes it stands for.
 *
 * @phpstan-param array{\Closure() : mixed, \Closure() : string} $old
 * @phpstan-param array{\Closure() : mixed, \Closure() : string} $adapter
 * @phpstan-param array{\Closure() : mixed, \Closure() : string} $native
 * @return mixed[]
 */
function compare(string $label, int $iterations, array $old, array $adapter, array $native) : array{
	$expected = $old[1]();
	foreach(["adapter" => $adapter[1](), "native" => $native[1]()] as $path => $bytes){
		if($bytes !== $expected){
			throw new \RuntimeException("$label: $path output differs from BedrockProtocol (" . bin2hex($bytes) . " vs " . bin2hex($expected) . ")");
		}
	}
	$before = measure($iterations, $old[0]);
	$after = measure($iterations, $native[0]);
	return [
		"bytes" => strlen($expected),
		"bedrock_protocol" => $before,
		"adapter" => measure($iterations, $adapter[0]),
		"native" => $after,
		"speedup" => round($before["us"] / $after["us"], 1),
	];
}

/**
 * Compares receiving one packet: from its bytes to the value a handler is given.
 *
 * @return mixed[]
 */
function inbound(string $label, string $buffer, int $iterations) : array{
	$pool = PacketPool::getInstance();
	$decodeLegacy = static function() use ($pool, $buffer) : DataPacket{
		$packet = $pool->getPacket($buffer) ?? throw new \RuntimeException("Unknown packet");
		if(!$packet instanceof DataPacket){
			throw new \RuntimeException("Unexpected packet type");
		}
		$packet->decode(new ByteBufferReader($buffer));
		return $packet;
	};
	ProtocolClasses::bind();
	$decodeNative = static fn() : Packet => EncodedPacket::fromBytes($buffer)->getPacket();
	return compare(
		$label,
		$iterations,
		[$decodeLegacy, static fn() : string => legacyEncode($decodeLegacy())],
		//the adapter read the packet ID natively, then decoded with BedrockProtocol
		[static fn() : DataPacket => Protocol::packetId($buffer) > 0 ? $decodeLegacy() : throw new \RuntimeException("No packet ID"), static fn() : string => legacyEncode($decodeLegacy())],
		[$decodeNative, static fn() : string => Protocol::encode(Protocol::packetId($buffer), $decodeNative())]
	);
}

/**
 * @phpstan-template T of EntityMetadataCollection|EntityMetadata
 * @phpstan-param T $metadata
 * @phpstan-return T
 */
function mobMetadata(EntityMetadataCollection|EntityMetadata $metadata, int $id) : EntityMetadataCollection|EntityMetadata{
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
 * Compares building and batching what a viewer receives from a group of mobs and a few sounds. With $spawn every mob
 * sends all of its metadata, as on spawning, otherwise the mobs move and three of them send one changed property.
 *
 * @return mixed[]
 */
function outboundTick(int $mobs, bool $spawn, int $iterations) : array{
	$positions = array_map(fn(int $i) => new Vector3(100.5 + $i, 64.0, -20.25 - $i), range(0, $mobs - 1));
	//each path gets its own mobs, so their metadata changes the same way on every call
	$legacyPackets = static function() use ($positions, $spawn, $mobs) : \Closure{
		$legacyMetadata = array_map(function(int $i) : EntityMetadataCollection{
			$metadata = mobMetadata(new EntityMetadataCollection(), $i);
			$metadata->clearDirtyProperties();
			return $metadata;
		}, range(0, $mobs - 1));
		$legacyTick = 0;
		return static function() use ($positions, $spawn, $legacyMetadata, &$legacyTick) : array{
		$packets = [];
		++$legacyTick;
		foreach($positions as $i => $position){
			$packets[] = MoveActorAbsolutePacket::create($i + 10, $position, 12.0, 90.0 + $i, 90.0 + $i, MoveActorAbsolutePacket::FLAG_GROUND);
			if($spawn){
				$packets[] = SetActorDataPacket::create($i + 10, $legacyMetadata[$i]->getAll(), new PropertySyncData([], []), 0);
			}elseif($i < 3){
				$legacyMetadata[$i]->setShort(EntityMetadataProperties::AIR, $legacyTick % 300);
				$packets[] = SetActorDataPacket::create($i + 10, $legacyMetadata[$i]->getDirty(), new PropertySyncData([], []), 0);
				$legacyMetadata[$i]->clearDirtyProperties();
			}
		}
		for($i = 0; $i < 3; ++$i){
			$packets[] = LevelSoundEventPacket::create(LevelSoundEvent::STEP, $positions[$i], -1, ":", false, false, -1, null);
		}
		return $packets;
		};
	};
	$nativeMetadata = array_map(function(int $i) : EntityMetadata{
		$metadata = mobMetadata(new EntityMetadata(), $i);
		$metadata->clearDirtyProperties();
		return $metadata;
	}, range(0, $mobs - 1));
	$nativeTick = 0;
	$nativePackets = static function() use ($positions, $spawn, $nativeMetadata, &$nativeTick) : array{
		$packets = [];
		++$nativeTick;
		foreach($positions as $i => $position){
			$packets[] = new NetMoveActorAbsolutePacket(
				actorRuntimeId: $i + 10,
				header: MoveActorAbsolutePacket::FLAG_GROUND,
				position: $position,
				rotationX: Fields::rotationByte(12.0),
				rotationY: Fields::rotationByte(90.0 + $i),
				rotationYHead: Fields::rotationByte(90.0 + $i),
			);
			if($spawn){
				$data = $nativeMetadata[$i]->getAll();
			}elseif($i < 3){
				$nativeMetadata[$i]->setShort(EntityMetadataProperties::AIR, $nativeTick % 300);
				$data = $nativeMetadata[$i]->getDirty();
				$nativeMetadata[$i]->clearDirtyProperties();
			}else{
				continue;
			}
			$packets[] = new NetSetActorDataPacket(
				targetRuntimeId: $i + 10,
				actorData: $data,
				synchedProperties: new NetPropertySyncData(intEntriesList: [], floatEntriesList: []),
				tick: 0,
			);
		}
		for($i = 0; $i < 3; ++$i){
			$packets[] = LevelPackets::sound(LevelSoundEvent::STEP, $positions[$i], false);
		}
		return $packets;
	};
	$oldPackets = $legacyPackets();
	$old = static function() use ($oldPackets) : string{
		$writer = new ByteBufferWriter();
		PacketBatch::encodePackets($writer, $oldPackets());
		return $writer->getData();
	};
	//the adapter encoded with BedrockProtocol, then batched the encoded strings natively
	$adapterPackets = $legacyPackets();
	$adapter = static fn() : string => NetworkSession::encodeBatch(array_map(static fn(DataPacket $packet) : string => EncodedPacket::fromBytes(legacyEncode($packet))->getBytes(), $adapterPackets()));
	$native = static fn() : string => NetworkSession::encodeBatch($nativePackets());
	return compare("tick", $iterations, [$old, $old], [$adapter, $adapter], [$native, $native]);
}

function authInput() : string{
	$flags = new BitSet(PlayerAuthInputFlags::NUMBER_OF_FLAGS);
	foreach([PlayerAuthInputFlags::UP, PlayerAuthInputFlags::SPRINTING, PlayerAuthInputFlags::START_SPRINTING] as $flag){
		$flags->set($flag, true);
	}
	return legacyEncode(PlayerAuthInputPacket::create(
		new Vector3(100.5, 65.62, -20.25), 12.5, 270.0, 270.0, 0.0, 1.0, $flags, InputMode::MOUSE_KEYBOARD, PlayMode::NORMAL, InteractionMode::CROSSHAIR,
		new Vector2(12.5, 270.0), 123456, new Vector3(0.0, -0.08, 0.28), null, null, null, null, 0.0, 1.0, new Vector3(0.0, 0.0, 1.0), new Vector2(0.0, 1.0)
	));
}

function useItem() : string{
	$item = new ItemStackWrapper(0, TypeConverter::getInstance()->coreItemStackToNet(VanillaItems::DIAMOND_SWORD()->setCustomName("named")));
	return legacyEncode(InventoryTransactionPacket::create(0, null, UseItemTransactionData::new(
		[], UseItemTransactionData::ACTION_CLICK_BLOCK, TriggerType::PLAYER_INPUT, new BlockPosition(10, 64, -3), 1, 0, HandSlot::MAINHAND, $item,
		new Vector3(10.5, 65.62, -2.5), new Vector3(0.5, 1.0, 0.5), 0, PredictedResult::SUCCESS, 0
	)));
}

function stackRequest() : string{
	$hotbar = new ItemStackRequestSlotInfo(new FullContainerName(ContainerUIIds::HOTBAR), 0, 5);
	$cursor = new ItemStackRequestSlotInfo(new FullContainerName(ContainerUIIds::CURSOR), 0, 0);
	$inventory = new ItemStackRequestSlotInfo(new FullContainerName(ContainerUIIds::INVENTORY), 12, 0);
	return legacyEncode(ItemStackRequestPacket::create([
		new ItemStackRequest(-3, [new TakeStackRequestAction(32, $hotbar, $cursor)], [], 0),
		new ItemStackRequest(-5, [new PlaceStackRequestAction(32, $cursor, $inventory)], [], 0),
	]));
}

echo json_encode([
	"inbound" => [
		"player_auth_input" => inbound("player_auth_input", authInput(), 20000),
		"inventory_transaction_use_item" => inbound("inventory_transaction", useItem(), 20000),
		"item_stack_request_take_place" => inbound("item_stack_request", stackRequest(), 20000),
	],
	"outbound" => [
		"spawn_20_mobs" => outboundTick(20, true, 500),
		"tick_20_moving_mobs" => outboundTick(20, false, 2000),
	],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
