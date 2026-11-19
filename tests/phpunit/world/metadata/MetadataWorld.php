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

namespace quark\world\metadata;

use quark\block\RuntimeBlockStateRegistry;
use quark\block\VanillaBlocks;
use quark\math\collision\CollisionRegion;
use quark\pulse\internal\PulseZones;
use quark\Server;
use quark\utils\ReversePriorityQueue;
use quark\world\format\Chunk;
use quark\world\format\io\MetadataWorldProvider;
use quark\world\format\io\WritableWorldProvider;
use quark\world\World;
use quark\world\WorldPulse;
use function spl_object_id;

/**
 * A World over a real provider, built without a server the way {@see World::__construct()} would set it up.
 */
final class MetadataWorld{

	public readonly World $world;

	public function __construct(public readonly WritableWorldProvider $provider){
		PulseZones::init();
		VanillaBlocks::AIR();

		$world = (new \ReflectionClass(World::class))->newInstanceWithoutConstructor();
		$set = static function(string $property, mixed $value) use ($world) : void{
			(new \ReflectionProperty(World::class, $property))->setValue($world, $value);
		};
		$set("server", (new \ReflectionClass(Server::class))->newInstanceWithoutConstructor());
		$set("folderName", "metadata_test");
		$set("displayName", "metadata_test");
		$set("logger", new \PrefixedLogger(\GlobalLogger::get(), "MetadataWorld"));
		$set("worldId", 0x7ffe0000 + spl_object_id($world));
		$set("blockStateRegistry", RuntimeBlockStateRegistry::getInstance());
		$set("minY", $provider->getWorldMinY());
		$set("maxY", $provider->getWorldMaxY());
		$set("collisionRegion", new CollisionRegion());
		$queue = new ReversePriorityQueue();
		$queue->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
		$set("scheduledBlockUpdateQueue", $queue);
		$set("neighbourBlockUpdateQueue", new \SplQueue());
		$set("provider", $provider);
		$set("time", 0);
		$set("weatherDuration", 0);
		$set("pulse", new WorldPulse($world));
		if($provider instanceof MetadataWorldProvider && ($record = $provider->loadWorldMetadata()) !== null){
			$set("metadata", new WorldMetadata($world, $provider, $record));
		}
		$this->world = $world;
	}

	public function generate(int $chunkX, int $chunkZ) : void{
		$this->world->setChunk($chunkX, $chunkZ, new Chunk([], true));
	}

	/** Saves and closes the world the way a shutdown does, without the rest of World::onUnload(). */
	public function close() : void{
		$this->world->save(true);
		foreach($this->world->getLoadedChunks() as $hash => $_){
			World::getXZ($hash, $chunkX, $chunkZ);
			$this->world->unloadChunk($chunkX, $chunkZ, false);
		}
		$this->provider->close();
		(new \ReflectionProperty(World::class, "unloaded"))->setValue($this->world, true);
	}
}
