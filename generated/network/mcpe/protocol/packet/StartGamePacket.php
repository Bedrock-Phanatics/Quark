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

namespace quark\network\mcpe\protocol\packet;

use Bedwire\PacketId;
use pocketmine\math\Vector2;
use pocketmine\math\Vector3;
use quark\network\mcpe\protocol\types\EventsServerTelemetryData;
use quark\network\mcpe\protocol\types\LevelSettings;
use quark\network\mcpe\protocol\types\PlayerMovementSettings;
use quark\network\mcpe\protocol\types\ServerBlockProperty;
use quark\network\mcpe\protocol\types\ServerConfigurationJoinInfo;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class StartGamePacket{
	public const int ID = PacketId::START_GAME;

	/**
	 * @param list<ServerBlockProperty> $blockProperties
	 */
	public function __construct(
		public int $entityId,
		public int $runtimeId,
		public int $gameType,
		public Vector3 $position,
		public Vector2 $rotation,
		public LevelSettings $settings,
		public string $levelId,
		public string $levelName,
		public string $templateContentIdentity,
		public bool $isTrial,
		public PlayerMovementSettings $movementSettings,
		public int $levelCurrentTime,
		public int $enchantmentSeed,
		public array $blockProperties,
		public string $multiplayerCorrelationId,
		public bool $enableItemStackNetManager,
		public string $serverVersion,
		public string $playerPropertyData,
		public int $serverBlockTypeRegistryChecksum,
		public string $worldTemplateId,
		public bool $serverEnabledClientSideGeneration,
		public bool $blockNetworkIdsAreHashes,
		public bool $networkPermissions,
		public ?ServerConfigurationJoinInfo $serverConfigurationJoinInfo,
		public EventsServerTelemetryData $serverTelemetryData,
	){}
}
