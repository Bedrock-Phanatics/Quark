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

use Bedwire\PacketId;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\EditorWorldType;
use pocketmine\network\mcpe\protocol\types\GeneratorType;
use pocketmine\network\mcpe\protocol\types\MultiplayerGameVisibility;
use pocketmine\network\mcpe\protocol\types\PlayerPermissions;
use pocketmine\network\mcpe\protocol\types\SpawnSettings;
use quark\network\mcpe\cache\CraftingDataCache;
use quark\network\mcpe\cache\StaticPacketCache;
use quark\network\mcpe\InventoryManager;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\Fields;
use quark\network\mcpe\protocol\packet\RequestChunkRadiusPacket;
use quark\network\mcpe\protocol\Packets;
use quark\player\Player;
use quark\pulse\internal\PulseZones;
use quark\Server;
use quark\VersionInfo;
use Ramsey\Uuid\Uuid;
use function sprintf;

/**
 * Handler used for the pre-spawn phase of the session.
 */
#[SilentDiscard(PacketId::PLAYER_AUTH_INPUT, comment: "Spammed after StartGame even though player has no controls")]
#[SilentDiscard(PacketId::SERVER_BOUND_LOADING_SCREEN, "Not needed")]
class PreSpawnPacketHandler extends PacketHandler{
	//an empty compound tag in the network NBT encoding
	private const EMPTY_COMPOUND = "\x0a\x00\x00";

	public function __construct(
		private Server $server,
		private Player $player,
		private NetworkSession $session,
		private InventoryManager $inventoryManager
	){}

	public function setUp() : void{
		$playerNetworkSendPreSpawnGameDataScope = PulseZones::$playerNetworkSendPreSpawnGameData->start();
		try{
			$location = $this->player->getLocation();
			$world = $location->getWorld();

			$typeConverter = $this->session->getTypeConverter();

			$this->session->getLogger()->debug("Sending JigsawStructureData");
			$this->session->sendDataPacket(StaticPacketCache::getInstance()->getJigsawStructureData());

			$this->session->getLogger()->debug("Sending VoxelShapesPacket");
			$this->session->sendDataPacket(StaticPacketCache::getInstance()->getVoxelShapes());

			$this->session->getLogger()->debug("Preparing StartGamePacket");
			$disabledGameRule = fn(string $name) => ["rule_name" => $name, "rule_can_be_modified" => false, "rule_value" => ["bool" => false]];
			$this->session->sendDataPacket(Packets::startGame([
				"entity_id" => $this->player->getId(),
				"runtime_id" => $this->player->getId(),
				"game_type" => $typeConverter->coreGameModeToProtocol($this->player->getGamemode())->value,
				"position" => Fields::vec3($this->player->getOffsetPosition($location)),
				"rotation" => Fields::vec2($location->pitch, $location->yaw),
				"settings" => [
					"seed" => -1,
					"spawn_settings" => ["spawn_biome_type" => SpawnSettings::BIOME_TYPE_DEFAULT, "user_defined_biome_name" => "", "dimension" => DimensionIds::OVERWORLD], //TODO: implement this properly
					"generator_type" => GeneratorType::OVERWORLD,
					"game_type" => $typeConverter->coreGameModeToProtocol($this->server->getGamemode())->value,
					"is_hardcore" => false,
					"game_difficulty" => $world->getDifficulty(),
					"default_spawn_block_position" => Fields::blockPosition($world->getSpawnLocation()),
					"achievements_disabled" => true,
					"editor_world_type" => EditorWorldType::NON_EDITOR,
					"is_created_in_editor" => false,
					"is_exported_from_editor" => false,
					"day_cycle_stop_time" => $world->getTime(),
					"education_edition_offer" => 0,
					"education_features_enabled" => false,
					"education_product_id" => "",
					"rain_level" => 0.0, //TODO: implement these properly
					"lightning_level" => 0.0,
					"has_confirmed_platform_locked_content" => false,
					"multiplayer_game_intent" => true,
					"lan_broadcast_intent" => true,
					"xbox_live_broadcast_setting" => MultiplayerGameVisibility::PUBLIC,
					"platform_broadcast_setting" => MultiplayerGameVisibility::PUBLIC,
					"commands_enabled" => true,
					"texture_packs_required" => true,
					"rule_data" => [
						$disabledGameRule("naturalregeneration"), //Hack for client side regeneration
						$disabledGameRule("locatorbar"), //Disable client-side tracking of nearby players
					],
					"experiments" => ["toggles" => [], "experiments_ever_toggled" => false],
					"has_bonus_chest_enabled" => false,
					"start_with_map_enabled" => false,
					"player_permissions" => PlayerPermissions::MEMBER->value,
					"server_chunk_tick_range" => 4,
					"has_locked_behavior_pack" => false,
					"has_locked_resource_pack" => false,
					"is_from_locked_template" => false,
					"use_msa_gamertags_only" => false,
					"is_from_world_template" => false,
					"is_world_template_option_locked" => false,
					"only_spawn_v1_villagers" => false,
					"persona_disabled" => false,
					"custom_skins_disabled" => false,
					"emote_chat_muted" => false,
					"base_game_version" => ProtocolInfo::MINECRAFT_VERSION_NETWORK,
					"limited_world_width" => 0,
					"limited_world_depth" => 0,
					"nether_type" => true,
					"edu_shared_uri_resource" => ["button_name" => "", "link_uri" => ""],
					"override_force_experimental_gameplay" => null,
					"chat_restriction_level" => 0,
					"disable_player_interactions" => false,
					"server_editor_connection_policy" => 0,
					"allow_anonymous_block_drops_in_editor_worlds" => false,
				],
				"level_id" => "",
				"level_name" => $this->server->getMotd(),
				"template_content_identity" => "",
				"is_trial" => false,
				"movement_settings" => ["rewind_history_size" => 0, "server_authoritative_block_breaking" => true],
				"level_current_time" => 0,
				"enchantment_seed" => 0,
				"block_properties" => StaticPacketCache::getInstance()->getBlockPaletteEntries(),
				"multiplayer_correlation_id" => "",
				"enable_item_stack_net_manager" => true,
				"server_version" => sprintf("%s %s", VersionInfo::NAME, VersionInfo::VERSION()->getFullVersion(true)),
				"player_property_data" => self::EMPTY_COMPOUND, //TODO: we don't care about this right now
				"server_block_type_registry_checksum" => 0,
				"world_template_id" => Fields::uuid(Uuid::fromString(Uuid::NIL)),
				"server_enabled_client_side_generation" => false,
				"block_network_ids_are_hashes" => false,
				"network_permissions" => true, //disables client sounds
				"server_configuration_join_info" => null,
				"server_telemetry_data" => ["server_id" => "", "scenario_id" => "", "world_id" => "", "owner_id" => ""],
			]));

			$this->session->getLogger()->debug("Sending items");
			$items = [];
			foreach($typeConverter->getItemTypeDictionary()->getEntries() as $entry){
				$items[] = [
					"item_name" => $entry->getStringId(),
					"item_id" => $entry->getNumericId(),
					"is_component_based" => $entry->isComponentBased(),
					"item_version" => $entry->getVersion(),
					"item_component_data" => $entry->getComponentNbt()->getEncodedNbt(),
				];
			}
			$this->session->sendDataPacket(Packets::itemRegistry(["item_data" => $items]));

			$this->session->getLogger()->debug("Sending actor identifiers");
			$this->session->sendDataPacket(StaticPacketCache::getInstance()->getAvailableActorIdentifiers());

			$this->session->getLogger()->debug("Sending biome definitions");
			$this->session->sendDataPacket(StaticPacketCache::getInstance()->getBiomeDefs());

			$this->session->getLogger()->debug("Sending attributes");
			$this->session->getEntityEventBroadcaster()->syncAttributes([$this->session], $this->player, $this->player->getAttributeMap()->getAll());

			$this->session->getLogger()->debug("Sending available commands");
			$this->session->syncAvailableCommands();

			$this->session->getLogger()->debug("Sending abilities");
			$this->session->syncAbilities($this->player);
			$this->session->syncAdventureSettings();

			$this->session->getLogger()->debug("Sending effects");
			foreach($this->player->getEffects()->all() as $effect){
				$this->session->getEntityEventBroadcaster()->onEntityEffectAdded([$this->session], $this->player, $effect, false);
			}

			$this->session->getLogger()->debug("Sending actor metadata");
			$this->player->sendData([$this->player]);

			$this->session->getLogger()->debug("Sending inventory");
			$this->inventoryManager->syncAll();
			$this->inventoryManager->syncSelectedHotbarSlot();

			$this->session->getLogger()->debug("Sending creative inventory data");
			$this->inventoryManager->syncCreative();

			$this->session->getLogger()->debug("Sending crafting data");
			$this->session->sendDataPacket(CraftingDataCache::getInstance()->getCache($this->server->getCraftingManager()));

			$this->session->getLogger()->debug("Sending player list");
			$this->session->syncPlayerList($this->server->getOnlinePlayers());
		}finally{
			PulseZones::$playerNetworkSendPreSpawnGameData->stop($playerNetworkSendPreSpawnGameDataScope);
		}
	}

	public function handleRequestChunkRadius(RequestChunkRadiusPacket $packet) : bool{
		$this->player->setViewDistance($packet->chunkRadius);

		return true;
	}
}
