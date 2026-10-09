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

namespace quark\pulse\internal;

use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use quark\block\tile\Tile;
use quark\entity\Entity;
use quark\event\Event;
use quark\pulse\Pulse;
use quark\pulse\PulseZone;
use quark\scheduler\AsyncTask;
use quark\scheduler\TaskHandler;
use function hash;
use function preg_match;
use function strlen;

/**
 * @internal
 */
final class PulseZones{
	private static bool $initialized = false;
	public static PulseZone $serverTick;
	public static PulseZone $serverInterrupts;
	public static PulseZone $memoryManager;
	public static PulseZone $garbageCollector;
	public static PulseZone $titleTick;
	public static PulseZone $playerNetworkSend;
	public static PulseZone $playerNetworkSendCompress;
	public static PulseZone $playerNetworkSendCompressBroadcast;
	public static PulseZone $playerNetworkSendCompressSessionBuffer;
	public static PulseZone $playerNetworkSendEncrypt;
	public static PulseZone $playerNetworkSendInventorySync;
	public static PulseZone $playerNetworkSendPreSpawnGameData;
	public static PulseZone $playerNetworkReceive;
	public static PulseZone $playerNetworkReceiveDecompress;
	public static PulseZone $playerNetworkReceiveDecrypt;
	public static PulseZone $playerChunkOrder;
	public static PulseZone $playerChunkSend;
	public static PulseZone $connection;
	public static PulseZone $scheduler;
	public static PulseZone $schedulerAsync;
	public static PulseZone $serverCommand;
	public static PulseZone $permissibleCalculation;
	public static PulseZone $permissibleCalculationDiff;
	public static PulseZone $permissibleCalculationCallback;
	public static PulseZone $entityMove;
	public static PulseZone $entityMoveCollision;
	public static PulseZone $projectileMove;
	public static PulseZone $projectileMoveRayTrace;
	public static PulseZone $playerCheckNearEntities;
	public static PulseZone $entityBaseTick;
	public static PulseZone $livingEntityBaseTick;
	public static PulseZone $itemEntityBaseTick;
	public static PulseZone $playerCommand;
	public static PulseZone $playerMove;
	public static PulseZone $craftingDataCacheRebuild;
	public static PulseZone $redstone;
	public static PulseZone $redstoneScheduler;
	public static PulseZone $redstoneWireNetworks;
	public static PulseZone $redstonePistons;
	public static PulseZone $redstoneDispensers;
	public static PulseZone $syncPlayerDataLoad;
	public static PulseZone $syncPlayerDataSave;
	public static PulseZone $broadcastPackets;
	/** @var array<string, PulseZone> */
	private static array $dynamic = [];
	/** @var array<string, array<string, PulseZone>> */
	private static array $packets = [];
	/** @var array<string, array<string, PulseZone>> */
	private static array $tasks = [];

	public static function init() : void{
		if(self::$initialized){ return; }
		self::$serverTick = Pulse::zone("server.tick");
		self::$serverInterrupts = Pulse::zone("server.interrupts");
		self::$memoryManager = Pulse::zone("server.memory");
		self::$garbageCollector = Pulse::zone("server.gc");
		self::$titleTick = Pulse::zone("server.console_title");
		self::$playerNetworkSend = Pulse::zone("network.send");
		self::$playerNetworkSendCompress = Pulse::zone("network.compress");
		self::$playerNetworkSendCompressBroadcast = Pulse::zone("network.compress.broadcast");
		self::$playerNetworkSendCompressSessionBuffer = Pulse::zone("network.compress.session");
		self::$playerNetworkSendEncrypt = Pulse::zone("network.encrypt");
		self::$playerNetworkSendInventorySync = Pulse::zone("network.inventory");
		self::$playerNetworkSendPreSpawnGameData = Pulse::zone("network.spawn");
		self::$playerNetworkReceive = Pulse::zone("network.receive");
		self::$playerNetworkReceiveDecompress = Pulse::zone("network.decompress");
		self::$playerNetworkReceiveDecrypt = Pulse::zone("network.decrypt");
		self::$playerChunkOrder = Pulse::zone("player.chunk_order");
		self::$playerChunkSend = Pulse::zone("player.chunk_send");
		self::$connection = Pulse::zone("network.tick");
		self::$scheduler = Pulse::zone("server.scheduler");
		self::$schedulerAsync = Pulse::zone("server.async_results");
		self::$serverCommand = Pulse::zone("server.commands");
		self::$permissibleCalculation = Pulse::zone("permissions.calculate");
		self::$permissibleCalculationDiff = Pulse::zone("permissions.diff");
		self::$permissibleCalculationCallback = Pulse::zone("permissions.callbacks");
		self::$entityMove = Pulse::zone("entity.move");
		self::$entityMoveCollision = Pulse::zone("entity.collision");
		self::$projectileMove = Pulse::zone("entity.projectile.move");
		self::$projectileMoveRayTrace = Pulse::zone("entity.projectile.raytrace");
		self::$playerCheckNearEntities = Pulse::zone("player.near_entities");
		self::$entityBaseTick = Pulse::zone("entity.base_tick");
		self::$livingEntityBaseTick = Pulse::zone("entity.living_tick");
		self::$itemEntityBaseTick = Pulse::zone("entity.item_tick");
		self::$playerCommand = Pulse::zone("player.commands");
		self::$playerMove = Pulse::zone("player.move");
		self::$craftingDataCacheRebuild = Pulse::zone("network.crafting_cache");
		self::$redstone = Pulse::zone("redstone.tick");
		self::$redstoneScheduler = Pulse::zone("redstone.scheduled");
		self::$redstoneWireNetworks = Pulse::zone("redstone.wire");
		self::$redstonePistons = Pulse::zone("redstone.pistons");
		self::$redstoneDispensers = Pulse::zone("redstone.dispensers");
		self::$syncPlayerDataLoad = Pulse::zone("player.data_load");
		self::$syncPlayerDataSave = Pulse::zone("player.data_save");
		self::$broadcastPackets = Pulse::zone("network.broadcast");
		self::$initialized = true;
	}

	public static function getEntityZone(Entity $entity) : PulseZone{
		self::init();
		return self::dynamic("entity.tick." . $entity::class);
	}

	public static function getTileEntityZone(Tile $tile) : PulseZone{
		return self::dynamic("block_entity.tick." . $tile::class);
	}

	/** @param TaskHandler<*> $task */
	public static function getScheduledTaskZone(TaskHandler $task) : PulseZone{
		return self::dynamic("plugin.tasks." . $task->getOwnerName() . "." . $task->getTaskName());
	}

	/** @param class-string<Event> $event */
	public static function getEventHandlerZone(string $event, string $handlerName, string $plugin) : PulseZone{
		return self::dynamic("plugin.events.$plugin.$event.$handlerName");
	}

	public static function getEventZone(Event $event) : PulseZone{
		return self::$dynamic[$event::class] ??= self::dynamic("event." . $event::class);
	}

	public static function getReceiveDataPacketZone(ServerboundPacket $packet) : PulseZone{ return self::packet($packet, "receive"); }
	public static function getDecodeDataPacketZone(ServerboundPacket $packet) : PulseZone{ return self::packet($packet, "decode"); }
	public static function getHandleDataPacketZone(ServerboundPacket $packet) : PulseZone{ return self::packet($packet, "handle"); }
	public static function getSendDataPacketZone(ClientboundPacket $packet) : PulseZone{ return self::packet($packet, "send"); }
	public static function getEncodeDataPacketZone(ClientboundPacket $packet) : PulseZone{ return self::packet($packet, "encode"); }

	private static function packet(ClientboundPacket|ServerboundPacket $packet, string $phase) : PulseZone{
		return self::$packets[$phase][$packet::class] ??= self::dynamic("network.$phase." . $packet->getName());
	}

	public static function getAsyncTaskRunZone(AsyncTask $task) : PulseZone{ return self::task($task, "run"); }
	public static function getAsyncTaskCompletionZone(AsyncTask $task) : PulseZone{ return self::task($task, "complete"); }
	public static function getAsyncTaskProgressUpdateZone(AsyncTask $task) : PulseZone{ return self::task($task, "progress"); }

	private static function task(AsyncTask $task, string $phase) : PulseZone{
		return self::$tasks[$phase][$task::class] ??= self::dynamic("worker.$phase." . $task::class);
	}

	public static function dynamic(string $name) : PulseZone{
		if(strlen($name) > 256 || preg_match('//u', $name) !== 1){
			$name = "dynamic." . hash("sha256", $name);
		}
		return Pulse::zone($name);
	}
}
