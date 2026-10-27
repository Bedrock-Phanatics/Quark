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

namespace quark\network\mcpe\protocol\types;

use pocketmine\math\Vector3;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class LevelSettings{
	/**
	 * @param list<GameRule> $ruleData
	 */
	public function __construct(
		public int $seed,
		public SpawnSettings $spawnSettings,
		public int $generatorType,
		public int $gameType,
		public bool $isHardcore,
		public int $gameDifficulty,
		public Vector3 $defaultSpawnBlockPosition,
		public bool $achievementsDisabled,
		public int $editorWorldType,
		public bool $isCreatedInEditor,
		public bool $isExportedFromEditor,
		public int $dayCycleStopTime,
		public int $educationEditionOffer,
		public bool $educationFeaturesEnabled,
		public string $educationProductId,
		public float $rainLevel,
		public float $lightningLevel,
		public bool $hasConfirmedPlatformLockedContent,
		public bool $multiplayerGameIntent,
		public bool $lanBroadcastIntent,
		public int $xboxLiveBroadcastSetting,
		public int $platformBroadcastSetting,
		public bool $commandsEnabled,
		public bool $texturePacksRequired,
		public array $ruleData,
		public Experiments $experiments,
		public bool $hasBonusChestEnabled,
		public bool $startWithMapEnabled,
		public int $playerPermissions,
		public int $serverChunkTickRange,
		public bool $hasLockedBehaviorPack,
		public bool $hasLockedResourcePack,
		public bool $isFromLockedTemplate,
		public bool $useMsaGamertagsOnly,
		public bool $isFromWorldTemplate,
		public bool $isWorldTemplateOptionLocked,
		public bool $onlySpawnV1Villagers,
		public bool $personaDisabled,
		public bool $customSkinsDisabled,
		public bool $emoteChatMuted,
		public string $baseGameVersion,
		public int $limitedWorldWidth,
		public int $limitedWorldDepth,
		public bool $netherType,
		public EducationSharedResourceURI $eduSharedUriResource,
		public ?bool $overrideForceExperimentalGameplay,
		public int $chatRestrictionLevel,
		public bool $disablePlayerInteractions,
		public int $serverEditorConnectionPolicy,
		public bool $allowAnonymousBlockDropsInEditorWorlds,
	){}
}
