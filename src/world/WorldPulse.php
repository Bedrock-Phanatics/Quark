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

namespace quark\world;

use quark\pulse\internal\PulseZones;
use quark\pulse\PulseZone;

/**
 * @internal
 */
final class WorldPulse{
	public readonly PulseZone $setBlock;
	public readonly PulseZone $doBlockLightUpdates;
	public readonly PulseZone $doBlockSkyLightUpdates;
	public readonly PulseZone $doChunkUnload;
	public readonly PulseZone $scheduledBlockUpdates;
	public readonly PulseZone $neighbourBlockUpdates;
	public readonly PulseZone $randomChunkUpdates;
	public readonly PulseZone $randomChunkUpdatesChunkSelection;
	public readonly PulseZone $doChunkGC;
	public readonly PulseZone $entityTick;
	public readonly PulseZone $doTick;
	public readonly PulseZone $syncChunkSend;
	public readonly PulseZone $syncChunkSendPrepare;
	public readonly PulseZone $syncChunkLoad;
	public readonly PulseZone $syncChunkLoadData;
	public readonly PulseZone $syncChunkLoadEntities;
	public readonly PulseZone $syncChunkLoadTileEntities;
	public readonly PulseZone $syncDataSave;
	public readonly PulseZone $syncChunkSave;
	public readonly PulseZone $chunkPopulationOrder;
	public readonly PulseZone $chunkPopulationCompletion;

	public function __construct(World $world){
		$name = $world->getFolderName();
		$this->setBlock = PulseZones::dynamic("world.$name.blocks.set");
		$this->doBlockLightUpdates = PulseZones::dynamic("world.$name.light.block");
		$this->doBlockSkyLightUpdates = PulseZones::dynamic("world.$name.light.sky");
		$this->doChunkUnload = PulseZones::dynamic("world.$name.chunk.unload");
		$this->scheduledBlockUpdates = PulseZones::dynamic("world.$name.blocks.scheduled");
		$this->neighbourBlockUpdates = PulseZones::dynamic("world.$name.blocks.neighbours");
		$this->randomChunkUpdates = PulseZones::dynamic("world.$name.blocks.random");
		$this->randomChunkUpdatesChunkSelection = PulseZones::dynamic("world.$name.blocks.select_chunks");
		$this->doChunkGC = PulseZones::dynamic("world.$name.chunk.gc");
		$this->entityTick = PulseZones::dynamic("world.$name.entities");
		$this->doTick = PulseZones::dynamic("world.$name.tick");
		$this->syncChunkSend = PulseZones::dynamic("world.$name.chunk.send");
		$this->syncChunkSendPrepare = PulseZones::dynamic("world.$name.chunk.prepare");
		$this->syncChunkLoad = PulseZones::dynamic("world.$name.chunk.load");
		$this->syncChunkLoadData = PulseZones::dynamic("world.$name.chunk.load_data");
		$this->syncChunkLoadEntities = PulseZones::dynamic("world.$name.chunk.load_entities");
		$this->syncChunkLoadTileEntities = PulseZones::dynamic("world.$name.chunk.load_block_entities");
		$this->syncDataSave = PulseZones::dynamic("world.$name.save");
		$this->syncChunkSave = PulseZones::dynamic("world.$name.chunk.save");
		$this->chunkPopulationOrder = PulseZones::dynamic("world.$name.chunk.populate");
		$this->chunkPopulationCompletion = PulseZones::dynamic("world.$name.chunk.populate_complete");
	}
}
