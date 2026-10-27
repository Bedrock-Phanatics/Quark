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
use quark\network\mcpe\protocol\types\BossKilledEvent;
use quark\network\mcpe\protocol\types\CauldronInteractEvent;
use quark\network\mcpe\protocol\types\CauldronUsedEvent;
use quark\network\mcpe\protocol\types\CodeBuilderScoreboardEvent;
use quark\network\mcpe\protocol\types\ComposterInteractEvent;
use quark\network\mcpe\protocol\types\ItemUsedEvent;
use quark\network\mcpe\protocol\types\LegacyTelemetryEventEmpty;
use quark\network\mcpe\protocol\types\LegacyTelemetryEventInteraction;
use quark\network\mcpe\protocol\types\LegacyTelemetryEventSlashCommand;
use quark\network\mcpe\protocol\types\MobBornEvent;
use quark\network\mcpe\protocol\types\MobKilledEvent;
use quark\network\mcpe\protocol\types\PiglinBarterEvent;
use quark\network\mcpe\protocol\types\PlayerDiedEvent;
use quark\network\mcpe\protocol\types\PortalUsedEvent;
use quark\network\mcpe\protocol\types\RaidUpdateEvent;

/**
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-classes.php. Do not edit it manually.
 */
final class EventPacket{
	public const int ID = PacketId::EVENT;

	/**
	 * @param array{achievement: int}|array{interaction: LegacyTelemetryEventInteraction}|array{portal_created: int}|array{portal_used: PortalUsedEvent}|array{mob_killed: MobKilledEvent}|array{cauldron_used: CauldronUsedEvent}|array{player_died: PlayerDiedEvent}|array{boss_killed: BossKilledEvent}|array{slash_command: LegacyTelemetryEventSlashCommand}|array{mob_born: MobBornEvent}|array{poi_cauldron_used: CauldronInteractEvent}|array{composter_used: ComposterInteractEvent}|array{bell_used: int}|array{actor_definition: string}|array{raid_update: RaidUpdateEvent}|array{target_block_hit: int}|array{piglin_barter: PiglinBarterEvent}|array{player_waxed_or_unwaxed_copper: int}|array{code_builder_runtime_action: string}|array{code_builder_scoreboard: CodeBuilderScoreboardEvent}|array{item_used: ItemUsedEvent}|array{empty: LegacyTelemetryEventEmpty} $eventData
	 */
	public function __construct(
		public int $targetActorId,
		public int $eventType,
		public bool $usePlayerId,
		public array $eventData,
	){}
}
