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

namespace quark\command\defaults;

use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\utils\TextFormat;
use function count;
use function memory_get_usage;
use function number_format;
use function round;

class GarbageCollectorCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"gc",
			KnownTranslationFactory::quark_command_gc_description()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_GC);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		$chunksCollected = 0;
		$entitiesCollected = 0;

		$memory = memory_get_usage();

		foreach($sender->getServer()->getWorldManager()->getWorlds() as $world){
			$diff = [count($world->getLoadedChunks()), count($world->getEntities())];
			$world->doChunkGarbageCollection();
			$world->unloadChunks(true);
			$chunksCollected += $diff[0] - count($world->getLoadedChunks());
			$entitiesCollected += $diff[1] - count($world->getEntities());
			$world->clearCache(true);
		}

		$cyclesCollected = $sender->getServer()->getMemoryManager()->triggerGarbageCollector();

		$sender->sendMessage(KnownTranslationFactory::quark_command_gc_header()->format(TextFormat::GREEN . "---- " . TextFormat::RESET, TextFormat::GREEN . " ----" . TextFormat::RESET));
		$sender->sendMessage(KnownTranslationFactory::quark_command_gc_chunks(TextFormat::RED . number_format($chunksCollected))->prefix(TextFormat::GOLD));
		$sender->sendMessage(KnownTranslationFactory::quark_command_gc_entities(TextFormat::RED . number_format($entitiesCollected))->prefix(TextFormat::GOLD));

		$sender->sendMessage(KnownTranslationFactory::quark_command_gc_cycles(TextFormat::RED . number_format($cyclesCollected))->prefix(TextFormat::GOLD));
		$sender->sendMessage(KnownTranslationFactory::quark_command_gc_memoryFreed(TextFormat::RED . number_format(round((($memory - memory_get_usage()) / 1024) / 1024, 2), 2))->prefix(TextFormat::GOLD));
		return true;
	}
}
