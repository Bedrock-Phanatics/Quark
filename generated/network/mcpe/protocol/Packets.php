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

namespace quark\network\mcpe\protocol;

use Bedwire\PacketId;

/**
 * Factories for every packet of protocol 2193 (Minecraft 1.26.51), with the field shapes Bedwire\Protocol decodes
 * to and encodes from.
 * This class is generated from Bedwire\Protocol::schema() by build/codegen/protocol-packets.php. Do not edit it manually.
 *
 * @phpstan-type PlayStatusType int
 * @phpstan-type ConnectionDisconnectFailReason int
 * @phpstan-type DisconnectMessages array{messages: DisconnectMessagesData}|array{empty: null}
 * @phpstan-type DisconnectMessagesData array{message: string, filtered_message: string}
 * @phpstan-type PackIDVersionData array{pack_uuid: string, pack_version: string}
 * @phpstan-type PackInfoData array{pack_id_version: PackIDVersionData, pack_size: int, content_key: string, subpack_name: string, content_identity: string, has_scripts: bool, is_addon_pack: bool, is_ray_tracing_capable: bool, cdn_url: string}
 * @phpstan-type StackResourcePack array{pack_id: string, version: string, sub_pack_name: string}
 * @phpstan-type Experiments array{toggles: list<ExperimentData>, experiments_ever_toggled: bool}
 * @phpstan-type ExperimentData array{name: string, enabled: bool}
 * @phpstan-type ResourcePackClientResponseResponse array{cancel: string}|array{downloading: Downloading}|array{downloading_finished: string}|array{resource_pack_stack_finished: string}
 * @phpstan-type Downloading array{response_type: string, downloading_packs: list<string>}
 * @phpstan-type TextBody array{message_only: TextMessageOnly}|array{author_and_message: TextAuthorAndMessage}|array{message_and_params: TextMessageAndParams}
 * @phpstan-type TextMessageOnly array{message_type: TextPacketType, message: string}
 * @phpstan-type TextPacketType int
 * @phpstan-type TextAuthorAndMessage array{message_type: TextPacketType, player_name: string, message: string}
 * @phpstan-type TextMessageAndParams array{message_type: TextPacketType, message: string, parameter_list: list<string>}
 * @phpstan-type GameType int
 * @phpstan-type Vec3f array{x: float, y: float, z: float}
 * @phpstan-type Vec2f array{x: float, y: float}
 * @phpstan-type LevelSettings array{seed: int, spawn_settings: SpawnSettings, generator_type: GeneratorType, game_type: GameType, is_hardcore: bool, game_difficulty: LegacyDifficulty, default_spawn_block_position: BlockPosition, achievements_disabled: bool, editor_world_type: EditorWorldType, is_created_in_editor: bool, is_exported_from_editor: bool, day_cycle_stop_time: int, education_edition_offer: EducationEditionOffer, education_features_enabled: bool, education_product_id: string, rain_level: float, lightning_level: float, has_confirmed_platform_locked_content: bool, multiplayer_game_intent: bool, lan_broadcast_intent: bool, xbox_live_broadcast_setting: SocialGamePublishSetting, platform_broadcast_setting: SocialGamePublishSetting, commands_enabled: bool, texture_packs_required: bool, rule_data: list<GameRule>, experiments: Experiments, has_bonus_chest_enabled: bool, start_with_map_enabled: bool, player_permissions: PlayerPermissionLevel, server_chunk_tick_range: int, has_locked_behavior_pack: bool, has_locked_resource_pack: bool, is_from_locked_template: bool, use_msa_gamertags_only: bool, is_from_world_template: bool, is_world_template_option_locked: bool, only_spawn_v1_villagers: bool, persona_disabled: bool, custom_skins_disabled: bool, emote_chat_muted: bool, base_game_version: string, limited_world_width: int, limited_world_depth: int, nether_type: bool, edu_shared_uri_resource: EducationSharedResourceURI, override_force_experimental_gameplay: bool|null, chat_restriction_level: ChatRestrictionLevel, disable_player_interactions: bool, server_editor_connection_policy: ServerEditorConnectionPolicy, allow_anonymous_block_drops_in_editor_worlds: bool}
 * @phpstan-type SpawnSettings array{spawn_biome_type: SpawnBiomeType, user_defined_biome_name: string, dimension: int}
 * @phpstan-type SpawnBiomeType int
 * @phpstan-type GeneratorType int
 * @phpstan-type LegacyDifficulty int
 * @phpstan-type BlockPosition array{x: int, y: int, z: int}
 * @phpstan-type EditorWorldType int
 * @phpstan-type EducationEditionOffer int
 * @phpstan-type SocialGamePublishSetting int
 * @phpstan-type GameRule array{rule_name: string, rule_can_be_modified: bool, rule_value: GameRuleRuleValue}
 * @phpstan-type GameRuleRuleValue array{empty: null}|array{bool: bool}|array{int32: int}|array{float: float}
 * @phpstan-type PlayerPermissionLevel int
 * @phpstan-type EducationSharedResourceURI array{button_name: string, link_uri: string}
 * @phpstan-type ChatRestrictionLevel int
 * @phpstan-type ServerEditorConnectionPolicy int
 * @phpstan-type PlayerMovementSettings array{rewind_history_size: int, server_authoritative_block_breaking: bool}
 * @phpstan-type ServerBlockProperty array{block_name: string, block_definition: string}
 * @phpstan-type ServerConfigurationJoinInfo array{gathering: GatheringJoinInfo|null, client_store_entry_point: StoreEntryPointInfo|null, presence: string|null|null}
 * @phpstan-type GatheringJoinInfo array{experience_id: string, experience_name: string, world_id: string|null, world_name: string|null, creator_id: string, target_id: string|null, scenario_id: string|null, server_id: string|null}
 * @phpstan-type StoreEntryPointInfo array{store_id: string, store_name: string}
 * @phpstan-type EventsServerTelemetryData array{server_id: string, scenario_id: string, world_id: string, owner_id: string}
 * @phpstan-type NetworkItemStackDescriptor array{id: int, stack_size: int, aux_value: int, net_id_variant: int|null, block_runtime_id: int, user_data_buffer: string}
 * @phpstan-type DataItemEntry array{id: int, payload: DataItemEntryPayload}
 * @phpstan-type DataItemEntryPayload array{byte: DataItemBytePayload}|array{short: DataItemShortPayload}|array{int: DataItemIntPayload}|array{float: DataItemFloatPayload}|array{string: DataItemStringPayload}|array{compound_tag: DataItemCompoundTagPayload}|array{pos: DataItemPosPayload}|array{int64: DataItemInt64Payload}|array{vec3: DataItemVec3Payload}
 * @phpstan-type DataItemBytePayload array{type: DataItemType, value: int}
 * @phpstan-type DataItemType int
 * @phpstan-type DataItemShortPayload array{type: DataItemType, value: int}
 * @phpstan-type DataItemIntPayload array{type: DataItemType, value: int}
 * @phpstan-type DataItemFloatPayload array{type: DataItemType, value: float}
 * @phpstan-type DataItemStringPayload array{type: DataItemType, value: string}
 * @phpstan-type DataItemCompoundTagPayload array{type: DataItemType, value: string}
 * @phpstan-type DataItemPosPayload array{type: DataItemType, value: BlockPosition}
 * @phpstan-type DataItemInt64Payload array{type: DataItemType, value: int}
 * @phpstan-type DataItemVec3Payload array{type: DataItemType, value: Vec3f}
 * @phpstan-type PropertySyncData array{int_entries_list: list<PropertySyncDataPropertySyncIntEntry>, float_entries_list: list<PropertySyncDataPropertySyncFloatEntry>}
 * @phpstan-type PropertySyncDataPropertySyncIntEntry array{property_index: int, data: int}
 * @phpstan-type PropertySyncDataPropertySyncFloatEntry array{property_index: int, data: float}
 * @phpstan-type AbilityData array{target_player_raw_id: int, player_permissions: PlayerPermissionLevel, command_permissions: CommandPermissionLevel, layers: list<SerializedAbilitiesDataSerializedLayer>}
 * @phpstan-type CommandPermissionLevel int
 * @phpstan-type SerializedAbilitiesDataSerializedLayer array{serialized_layer: int, abilities_set: int, ability_values: int, fly_speed: float, vertical_fly_speed: float, walk_speed: float}
 * @phpstan-type EntityLink array{target_a: int, target_b: int, type: ActorLinkType, immediate: bool, passenger_initiated: bool, vehicle_angular_velocity: float}
 * @phpstan-type ActorLinkType int
 * @phpstan-type BuildPlatform int
 * @phpstan-type SyncedAttribute array{attribute_name: string, min_value: float, current_value: float, max_value: float}
 * @phpstan-type MoveActorAbsoluteData array{actor_runtime_id: int, header: int, position: Vec3f, rotation_x: int, rotation_y: int, rotation_y_head: int}
 * @phpstan-type PlayerPositionModeComponentPositionMode int
 * @phpstan-type MovePlayerTeleportData array{teleportation_cause: int, source_actor_type: int}
 * @phpstan-type ActorEventType int
 * @phpstan-type MobEffectEvent int
 * @phpstan-type AttributeData array{min_value: float, max_value: float, current_value: float, default_min_value: float, default_max_value: float, default_value: float, name: string, modifiers: list<AttributeModifier>}
 * @phpstan-type AttributeModifier array{id: string, name: string, amount: float, operation: int, operand: int, is_serializable: bool}
 * @phpstan-type LegacySetSlot array{container_enum: ContainerEnumName, slots: string}
 * @phpstan-type ContainerEnumName int
 * @phpstan-type InventoryTransactionTransaction array{normal_transaction_data: list<InventoryAction>}|array{inventory_mismatch_data: list<InventoryAction>}|array{item_use_inventory_transaction: ItemUseInventoryTransaction}|array{item_use_on_actor_inventory_transaction: ItemUseOnActorInventoryTransaction}|array{item_release_inventory_transaction: ItemReleaseInventoryTransaction}
 * @phpstan-type InventoryAction array{source: InventorySource, slot: int, from_item: NetworkItemStackDescriptor, to_item: NetworkItemStackDescriptor}
 * @phpstan-type InventorySource array{source_type: InventorySourceType, container_id: int|null, bit_flags: InventorySourceFlags|null}
 * @phpstan-type InventorySourceType int
 * @phpstan-type InventorySourceFlags int
 * @phpstan-type ItemUseInventoryTransaction array{actions: list<InventoryAction>, action_type: ItemUseInventoryTransactionActionType, trigger_type: ItemUseInventoryTransactionTriggerType, position: BlockPosition, face: int, slot: int, hand: HandSlot, item: NetworkItemStackDescriptor, from_position: Vec3f, click_position: Vec3f, target_block_id: int, client_interact_prediction: ItemUseInventoryTransactionPredictedResult, client_cooldown_state: ItemUseInventoryTransactionClientCooldownState}
 * @phpstan-type ItemUseInventoryTransactionActionType int
 * @phpstan-type ItemUseInventoryTransactionTriggerType int
 * @phpstan-type HandSlot int
 * @phpstan-type ItemUseInventoryTransactionPredictedResult int
 * @phpstan-type ItemUseInventoryTransactionClientCooldownState int
 * @phpstan-type ItemUseOnActorInventoryTransaction array{actions: list<InventoryAction>, runtime_id: int, action_type: ItemUseOnActorInventoryTransactionActionType, slot: int, item: NetworkItemStackDescriptor, from_position: Vec3f, hit_position: Vec3f}
 * @phpstan-type ItemUseOnActorInventoryTransactionActionType int
 * @phpstan-type ItemReleaseInventoryTransaction array{actions: list<InventoryAction>, action_type: ItemReleaseInventoryTransactionActionType, slot: int, item: NetworkItemStackDescriptor, from_position: Vec3f}
 * @phpstan-type ItemReleaseInventoryTransactionActionType int
 * @phpstan-type InteractAction int
 * @phpstan-type PlayerActionType int
 * @phpstan-type SpawnPositionType int
 * @phpstan-type AnimateAction int
 * @phpstan-type PlayerRespawnState int
 * @phpstan-type FullContainerName array{container_name: ContainerEnumName, dynamic_id: int|null}
 * @phpstan-type ShapedRecipePayload array{recipe_id: string, width: int, height: int, ingredients: list<RecipeIngredientRecipeIngredient>, results: list<NetworkItemInstanceDescriptor>, uuid: string, tag: string, priority: int, assume_symmetry: bool, unlocking_requirement: RecipeUnlockRequirementSerializedData|null, net_id: int}
 * @phpstan-type RecipeIngredientRecipeIngredient array{descriptor: Descriptor, count: int}
 * @phpstan-type Descriptor array{empty: DescriptorEmpty}|array{item: DescriptorItem}|array{molang: DescriptorMolang}|array{item_tag: DescriptorItemTag}
 * @phpstan-type DescriptorEmpty array{aux: int}
 * @phpstan-type DescriptorItem array{name: string, aux: int}
 * @phpstan-type DescriptorMolang array{expression: string, version: int}
 * @phpstan-type DescriptorItemTag array{tag: string, aux: int}
 * @phpstan-type NetworkItemInstanceDescriptor array{id: int, stack_size: int, aux_value: int, block_runtime_id: int, user_data_buffer: string}
 * @phpstan-type RecipeUnlockRequirementSerializedData array{unlocking_context: RecipeUnlockingRequirementUnlockingContext, unlocking_ingredients: list<RecipeIngredientRecipeIngredient>|null}
 * @phpstan-type RecipeUnlockingRequirementUnlockingContext int
 * @phpstan-type ShapelessRecipePayload array{recipe_id: string, ingredients: list<RecipeIngredientRecipeIngredient>, results: list<NetworkItemInstanceDescriptor>, uuid: string, tag: string, priority: int, unlocking_requirement: RecipeUnlockRequirementSerializedData|null, net_id: int}
 * @phpstan-type MultiRecipePayload array{multi_recipe_uuid: string, net_id: int}
 * @phpstan-type SmithingTransformRecipePayload array{recipe_id: string, template_ingredient: RecipeIngredientRecipeIngredient, base_ingredient: RecipeIngredientRecipeIngredient, addition_ingredient: RecipeIngredientRecipeIngredient, result: NetworkItemInstanceDescriptor, tag: string, net_id: int}
 * @phpstan-type SmithingTrimRecipePayload array{recipe_id: string, template_ingredient: RecipeIngredientRecipeIngredient, base_ingredient: RecipeIngredientRecipeIngredient, addition_ingredient: RecipeIngredientRecipeIngredient, tag: string, net_id: int}
 * @phpstan-type PotionMixDataEntry array{from_potion_id: int, from_item_aux: int, reagent_item_id: int, reagent_item_aux: int, to_potion_id: int, to_item_aux: int}
 * @phpstan-type ContainerMixDataEntry array{from_item_id: int, reagent_item_id: int, to_item_id: int}
 * @phpstan-type MaterialReducerDataEntry array{from_item_key: int, item_ids_and_counts: list<MaterialReducerEntryOutput>}
 * @phpstan-type MaterialReducerEntryOutput array{item_id: int, item_count: int}
 * @phpstan-type ChunkPosition array{x: int, z: int}
 * @phpstan-type PlayerListEntriesItem array{remove: PlayerListRemoveEntry}|array{add: PlayerListAddEntry}
 * @phpstan-type PlayerListRemoveEntry array{action: PlayerListPacketType, uuid: string}
 * @phpstan-type PlayerListPacketType int
 * @phpstan-type PlayerListAddEntry array{action: PlayerListPacketType, uuid: string, actor_unique_id: int, player_name: string, xbl_xuid: string, platform_online_id: string, build_platform: BuildPlatform, serialized_skin: SerializedSkinRef, is_teacher: bool, is_host: bool, is_sub_client: bool, player_color: int}
 * @phpstan-type SerializedSkinRef array{id: string, play_fab_id: string, resource_patch: string, image_data: SkinImage, animated_image_data: list<AnimatedImageData>, cape_image_data: SkinImage, geometry_data: string, geometry_data_min_engine_version: string, animation_data: string, cape_id: string, full_id: string, arm_size: PersonaArmSizeType, skin_color: int, persona_pieces: list<PersonaPiece>, piece_tint_colors: list<SerializedSkinRefPieceTintColorsEntry>, is_premium: bool, is_persona: bool, is_persona_cape_on_classic_skin: bool, is_primary_user: bool, overrides_player_appearance: bool, trusted_skin_flag: string, profile_hash: string}
 * @phpstan-type SkinImage array{width: int, height: int, image_bytes: string}
 * @phpstan-type AnimatedImageData array{skin_image: SkinImage, animated_texture_type: PersonaAnimatedTextureType, frames: float, animation_expression: PersonaAnimationExpression}
 * @phpstan-type PersonaAnimatedTextureType int
 * @phpstan-type PersonaAnimationExpression int
 * @phpstan-type PersonaArmSizeType int
 * @phpstan-type PersonaPiece array{piece_id: string, piece_type: PersonaPieceType, pack_id: string, is_default_piece: bool, product_id: string}
 * @phpstan-type PersonaPieceType int
 * @phpstan-type SerializedSkinRefPieceTintColorsEntry array{key: string, value: list<int>}
 * @phpstan-type SimpleEventSubtype int
 * @phpstan-type LegacyTelemetryType int
 * @phpstan-type EventEventData array{achievement: MinecraftEventingAchievementIds}|array{interaction: LegacyTelemetryEventInteraction}|array{portal_created: int}|array{portal_used: PortalUsedEvent}|array{mob_killed: MobKilledEvent}|array{cauldron_used: CauldronUsedEvent}|array{player_died: PlayerDiedEvent}|array{boss_killed: BossKilledEvent}|array{slash_command: LegacyTelemetryEventSlashCommand}|array{mob_born: MobBornEvent}|array{poi_cauldron_used: CauldronInteractEvent}|array{composter_used: ComposterInteractEvent}|array{bell_used: int}|array{actor_definition: string}|array{raid_update: RaidUpdateEvent}|array{target_block_hit: int}|array{piglin_barter: PiglinBarterEvent}|array{player_waxed_or_unwaxed_copper: int}|array{code_builder_runtime_action: string}|array{code_builder_scoreboard: CodeBuilderScoreboardEvent}|array{item_used: ItemUsedEvent}|array{empty: LegacyTelemetryEventEmpty}
 * @phpstan-type MinecraftEventingAchievementIds int
 * @phpstan-type LegacyTelemetryEventInteraction array{interacted_entity_id: int, interaction_type: MinecraftEventingInteractionType, interaction_actor_type: int, interaction_actor_variant: int, interaction_actor_color: int}
 * @phpstan-type MinecraftEventingInteractionType int
 * @phpstan-type PortalUsedEvent array{source_dimension_id: int, target_dimension_id: int}
 * @phpstan-type MobKilledEvent array{instigator_actor_id: int, target_actor_id: int, instigators_child_actor_type: ActorType, damage_source: int, trade_tier: int, trader_name: string}
 * @phpstan-type ActorType int
 * @phpstan-type CauldronUsedEvent array{contents_color: int, contents_type: int, fill_level: int}
 * @phpstan-type PlayerDiedEvent array{instigator_actor_id: int, instigator_mob_variant: int, damage_source: int, died_in_raid: bool}
 * @phpstan-type BossKilledEvent array{boss_actor_id: int, party_size: int, boss_type: int}
 * @phpstan-type LegacyTelemetryEventSlashCommand array{success_count: int, error_count: int, command_name: string, error_list: string}
 * @phpstan-type MobBornEvent array{born_baby_entity_type: int, born_baby_entity_variant: int, born_baby_color: int}
 * @phpstan-type CauldronInteractEvent array{block_interaction_type: MinecraftEventingPoiBlockInteractionType, item_id: int}
 * @phpstan-type MinecraftEventingPoiBlockInteractionType int
 * @phpstan-type ComposterInteractEvent array{block_interaction_type: MinecraftEventingPoiBlockInteractionType, item_id: int}
 * @phpstan-type RaidUpdateEvent array{current_wave: int, total_waves: int, success: bool}
 * @phpstan-type PiglinBarterEvent array{item_id: int, was_targeting_bartering_player: bool}
 * @phpstan-type CodeBuilderScoreboardEvent array{objective_name: string, score: int}
 * @phpstan-type ItemUsedEvent array{item_id: int, item_aux: int, use_method: int, count: int}
 * @phpstan-type LegacyTelemetryEventEmpty array{}
 * @phpstan-type MapTrackedObject array{type: MapItemTrackedActorType, entity_id: int|null, block_position: BlockPosition|null}
 * @phpstan-type MapItemTrackedActorType int
 * @phpstan-type MapDecoration array{image_type: MapDecorationType, rotation: int, x: int, y: int, label: string, color: int}
 * @phpstan-type MapDecorationType int
 * @phpstan-type PixelRequest array{pixel: int, index: int}
 * @phpstan-type BossEventUpdateType int
 * @phpstan-type BossBarColor int
 * @phpstan-type BossBarOverlay int
 * @phpstan-type CommandEnum array{name: string, values: list<int>}
 * @phpstan-type ChainedSubcommand array{name: string, sub_command_values: list<ChainedSubcommandValue>}
 * @phpstan-type ChainedSubcommandValue array{sub_command_first_value: int, sub_command_second_value: int}
 * @phpstan-type Command array{name: string, description: string, flags: int, permission_level: string, alias_enum: int, command_data_chained_subcommand_indexes: list<int>, overloads: list<CommandOverload>}
 * @phpstan-type CommandOverload array{is_chaining: bool, parameter_data: list<CommandParameter>}
 * @phpstan-type CommandParameter array{name: string, parse_symbol: int, is_optional: bool, options: int}
 * @phpstan-type DynamicEnum array{enum_name: string, enum_options: list<string>}
 * @phpstan-type CommandEnumConstraint array{enum_value_symbol: int, enum_symbol: int, constraint_indices: string}
 * @phpstan-type CommandOrigin array{type: string, uuid: string, request_id: string, player_id: int}
 * @phpstan-type CommandBlockUpdateTarget array{entity_command_target: int}|array{block_command_data: CommandBlockUpdateBlockCommandData}
 * @phpstan-type CommandBlockUpdateBlockCommandData array{block_position: BlockPosition, command_block_mode: int, redstone_mode: bool, is_conditional: bool}
 * @phpstan-type CommandOutputData array{output_type: string, success_count: int, output_messages: list<CommandOutputMessage>, data_set: string|null}
 * @phpstan-type CommandOutputMessage array{message_id: string, successful: bool, parameters: list<string>}
 * @phpstan-type SetTitleTitleType int
 * @phpstan-type StructureEditorData array{structure_name: string, filtered_structure_name: string, data_field: string, should_include_players: bool, should_show_bounding_box: bool, structure_block_type: StructureBlockType, structure_settings: StructureSettings, redstone_save_mode: StructureRedstoneSaveMode}
 * @phpstan-type StructureBlockType int
 * @phpstan-type StructureSettings array{structure_palette_name: string, should_ignore_entities: bool, should_ignore_blocks: bool, should_allow_non_ticking_player_and_ticking_area_chunks: bool, structure_size: BlockPosition, structure_offset: BlockPosition, last_edit_player: int, rotation: Rotation, mirror: Mirror, animation_mode: AnimationMode, animation_seconds: float, integrity_value: float, integrity_seed: int, rotation_pivot: Vec3f}
 * @phpstan-type Rotation int
 * @phpstan-type Mirror int
 * @phpstan-type AnimationMode int
 * @phpstan-type StructureRedstoneSaveMode int
 * @phpstan-type ShowStoreOfferRedirectType int
 * @phpstan-type BookEditOperation array{replace_page: BookEditActionReplacePage}|array{add_page: BookEditActionAddPage}|array{delete_page: int}|array{swap_pages: BookEditActionSwapPages}|array{finalize: BookEditActionFinalize}
 * @phpstan-type BookEditActionReplacePage array{page_index: int, page_text: string, photo_name: string}
 * @phpstan-type BookEditActionAddPage array{page_index: int, page_text: string, photo_name: string}
 * @phpstan-type BookEditActionSwapPages array{page_index: int, swap_with_index: int}
 * @phpstan-type BookEditActionFinalize array{title: string, author: string, xuid: string}
 * @phpstan-type NpcRequestRequestType int
 * @phpstan-type PhotoType int
 * @phpstan-type ModalFormCancelReason int
 * @phpstan-type SetScoreScoreInfoItem array{remove: RemoveScore}|array{change_player: ChangePlayerScore}|array{change_entity: ChangeEntityScore}|array{change_fake_player: ChangeFakePlayerScore}
 * @phpstan-type RemoveScore array{action: string, scoreboard_id: int, objective_name: string|null}
 * @phpstan-type ChangePlayerScore array{action: string, scoreboard_id: int, objective_name: string, score_value: int, player_unique_id: int}
 * @phpstan-type ChangeEntityScore array{action: string, scoreboard_id: int, objective_name: string, score_value: int, actor_id: int}
 * @phpstan-type ChangeFakePlayerScore array{action: string, scoreboard_id: int, objective_name: string, score_value: int, fake_player_name: string}
 * @phpstan-type LabTableType int
 * @phpstan-type LabTableReactionType int
 * @phpstan-type MoveActorDeltaData array{actor_runtime_id: int, new_position_x: float|null, new_position_y: float|null, new_position_z: float|null, rotation_x: int|null, rotation_y: int|null, rotation_y_head: int|null, is_on_ground: bool, force_move: bool, force_move_local_entity: bool, force_completion: bool, ticks: int}
 * @phpstan-type ScoreboardIdentityPacketType int
 * @phpstan-type ScoreboardIdentityPacketInfo array{scoreboard_id: int, player_unique_id: int|null}
 * @phpstan-type SoftEnumUpdateType int
 * @phpstan-type PacketMapOfBiomeNamesToDataEntry array{key: int, value: BiomeDefinition}
 * @phpstan-type BiomeDefinition array{id: int, temperature: float, downfall: float, foliage_snow: float, depth: float, scale: float, map_water_color_argb: int, rain: bool, tags: list<int>|null, chunk_gen_data: BiomeDefinitionChunkGenData|null}
 * @phpstan-type BiomeDefinitionChunkGenData array{climate: BiomeClimate|null, consolidated_features: list<BiomeConsolidatedFeature>|null, mountain_params: BiomeMountainParameters|null, surface_material_adjustments: list<BiomeElementData>|null, overworld_gen_rules: BiomeOverworldGenRulesData|null, multinoise_gen_rules: BiomeMultiNoiseRules|null, legacy_world_gen_rules: list<BiomeConditionalTransformation>|null, replacement_biomes: list<BiomeReplacementData>|null, village_type: VillageType|null, surface_builder_data: BiomeSurfaceBuilder|null, subsurface_builder_data: BiomeSurfaceBuilder|null}
 * @phpstan-type BiomeClimate array{temperature: float, downfall: float, snow_accumulation_min: float, snow_accumulation_max: float}
 * @phpstan-type BiomeConsolidatedFeature array{scatter: BiomeScatterParameter, feature: int, identifier: int, pass: int, can_use_internal_feature: bool}
 * @phpstan-type BiomeScatterParameter array{coordinates: list<BiomeCoordinate>, eval_order: CoordinateEvaluationOrder, chance_percent_type: int, chance_percent: int, chance_numerator: int, chance_denominator: int, iterations_type: int, iterations: int}
 * @phpstan-type BiomeCoordinate array{min_value_type: int, min_value: int, max_value_type: int, max_value: int, grid_offset: int, grid_step_size: int, distribution: RandomDistributionType}
 * @phpstan-type RandomDistributionType int
 * @phpstan-type CoordinateEvaluationOrder int
 * @phpstan-type BiomeMountainParameters array{steep_block: int, north_slopes: bool, south_slopes: bool, west_slopes: bool, east_slopes: bool, top_slide_enabled: bool}
 * @phpstan-type BiomeElementData array{noise_freq_scale: float, noise_lower_bound: float, noise_upper_bound: float, height_min_type: int, height_min: int, height_max_type: int, height_max: int, adjusted_materials: BiomeSurfaceMaterial}
 * @phpstan-type BiomeSurfaceMaterial array{top_block: int, mid_block: int, sea_floor_block: int, foundation_block: int, sea_block: int, sea_floor_depth: int}
 * @phpstan-type BiomeOverworldGenRulesData array{hills_transformations: list<BiomeWeightedData>, mutate_transformations: list<BiomeWeightedData>, river_transformations: list<BiomeWeightedData>, shore_transformations: list<BiomeWeightedData>, pre_hills_edge: list<BiomeConditionalTransformation>, post_shore_edge: list<BiomeConditionalTransformation>, climate: list<BiomeTemperatureWeight>}
 * @phpstan-type BiomeWeightedData array{biome_identifier: int, weight: int}
 * @phpstan-type BiomeConditionalTransformation array{transforms_into: list<BiomeWeightedData>, condition_json: int, min_passing_neighbors: int}
 * @phpstan-type BiomeTemperatureWeight array{temperature: int, weight: int}
 * @phpstan-type BiomeMultiNoiseRules array{temperature: float, humidity: float, altitude: float, weirdness: float, weight: float}
 * @phpstan-type BiomeReplacementData array{replacement_biome: int, dimension: int, target_biomes: list<int>, amount: float, noise_frequency_scale: float, replacement_index: int}
 * @phpstan-type VillageType int
 * @phpstan-type BiomeSurfaceBuilder array{surface_materials: BiomeSurfaceMaterial|null, has_default_overworld_surface: bool, has_swamp_surface: bool, has_frozen_ocean_surface: bool, has_the_end_surface: bool, mesa_surface: BiomeMesaSurface|null, capped_surface: BiomeCappedSurface|null, noise_gradient_surface: BiomeNoiseGradientSurface|null}
 * @phpstan-type BiomeMesaSurface array{clay_material: int, hard_clay_material: int, bryce_pillars: bool, has_forest: bool}
 * @phpstan-type BiomeCappedSurface array{floor_blocks: list<int>, ceiling_blocks: list<int>, sea_block: int|null, foundation_block: int|null, beach_block: int|null}
 * @phpstan-type BiomeNoiseGradientSurface array{non_replaceable_blocks: list<int>, gradient_blocks: list<NoiseBlockSpecifier>, noise: NoiseDescriptor}
 * @phpstan-type NoiseBlockSpecifier array{noise: string, threshold: float, range: FloatRange, block: int}
 * @phpstan-type FloatRange array{min: float, max: float}
 * @phpstan-type NoiseDescriptor array{name: string, first_octave: int, amplitudes: list<float>}
 * @phpstan-type StructureTemplateRequestOperation int
 * @phpstan-type StructureTemplateResponseType int
 * @phpstan-type MissingBlobData array{blob_id: int, blob_data: string}
 * @phpstan-type EducationLevelSettings array{code_builder_default_uri: string, code_builder_title: string, can_resize_code_builder: bool, disable_legacy_title_bar: bool, post_process_filter: string, screenshot_border_resource_path: string, agent_capabilities: bool|null, local_settings: string|null, deprecated_always_false: bool, external_link_settings: EducationExternalLinkSettings|null}
 * @phpstan-type EducationExternalLinkSettings array{url: string, display_name: string}
 * @phpstan-type MultiplayerSettingsType int
 * @phpstan-type PacketCompressionAlgorithm int
 * @phpstan-type PlayerAuthInputInputData int
 * @phpstan-type InputMode int
 * @phpstan-type ClientPlayMode int
 * @phpstan-type NewInteractionModel int
 * @phpstan-type PackedItemUseLegacyInventoryTransaction array{legacy_request_id: int, legacy_set_item_slots: list<LegacySetSlot>|null, item_use_transaction: ItemUseInventoryTransaction}
 * @phpstan-type ItemStackRequestData array{client_request_id: int, actions: list<StackRequestAction>, strings_to_filter: list<string>, strings_to_filter_origin: TextProcessingEventOrigin}
 * @phpstan-type StackRequestAction array{take: TakeStackRequestAction}|array{place: PlaceStackRequestAction}|array{swap: SwapStackRequestAction}|array{drop: DropStackRequestAction}|array{destroy: DestroyStackRequestAction}|array{consume: ConsumeStackRequestAction}|array{create: CreateStackRequestAction}|array{lab_table_combine: ItemStackRequestActionType}|array{beacon_payment: BeaconPaymentStackRequestAction}|array{mine_block: MineBlockStackRequestAction}|array{craft_recipe: CraftRecipeStackRequestAction}|array{craft_recipe_auto: AutoCraftRecipeStackRequestAction}|array{craft_creative: CraftCreativeStackRequestAction}|array{craft_recipe_optional: CraftRecipeOptionalStackRequestAction}|array{craft_repair_and_disenchant: CraftRepairAndDisenchantStackRequestAction}|array{craft_loom: CraftLoomStackRequestAction}|array{craft_non_implemented: ItemStackRequestActionType}|array{craft_results: CraftResultsDeprecatedStackRequestAction}
 * @phpstan-type TakeStackRequestAction array{action_type: ItemStackRequestActionType, amount: int, source: StackRequestSlotInfo, destination: StackRequestSlotInfo}
 * @phpstan-type ItemStackRequestActionType int
 * @phpstan-type StackRequestSlotInfo array{full_container_name: FullContainerName, slot: int, net_id_variant: int}
 * @phpstan-type PlaceStackRequestAction array{action_type: ItemStackRequestActionType, amount: int, source: StackRequestSlotInfo, destination: StackRequestSlotInfo}
 * @phpstan-type SwapStackRequestAction array{action_type: ItemStackRequestActionType, source: StackRequestSlotInfo, destination: StackRequestSlotInfo}
 * @phpstan-type DropStackRequestAction array{action_type: ItemStackRequestActionType, amount: int, source: StackRequestSlotInfo, randomly: bool}
 * @phpstan-type DestroyStackRequestAction array{action_type: ItemStackRequestActionType, amount: int, source: StackRequestSlotInfo}
 * @phpstan-type ConsumeStackRequestAction array{action_type: ItemStackRequestActionType, amount: int, source: StackRequestSlotInfo}
 * @phpstan-type CreateStackRequestAction array{action_type: ItemStackRequestActionType, results_index: int}
 * @phpstan-type BeaconPaymentStackRequestAction array{action_type: ItemStackRequestActionType, primary_effect_id: int, secondary_effect_id: int}
 * @phpstan-type MineBlockStackRequestAction array{action_type: ItemStackRequestActionType, slot: int, predicted_durability: int, net_id_variant: int}
 * @phpstan-type CraftRecipeStackRequestAction array{action_type: ItemStackRequestActionType, recipe_net_id: int, number_of_requested_crafts: int}
 * @phpstan-type AutoCraftRecipeStackRequestAction array{action_type: ItemStackRequestActionType, recipe_net_id: int, number_of_requested_crafts: int, ingredients: list<RecipeRecipeIngredient>}
 * @phpstan-type RecipeRecipeIngredient array{item_descriptor: ItemDescriptor, stack_size: int}
 * @phpstan-type ItemDescriptor array{empty_item: ItemDescriptorType}|array{item_name: DefaultItemDescriptor}|array{molang_item: MoLangItemDescriptor}|array{item_tag: ItemTagItemDescriptor}
 * @phpstan-type ItemDescriptorType int
 * @phpstan-type DefaultItemDescriptor array{descriptor_type: ItemDescriptorType, full_name: string, aux_value: int}
 * @phpstan-type MoLangItemDescriptor array{descriptor_type: ItemDescriptorType, tag_expression: string, molang_version: MolangVersion}
 * @phpstan-type MolangVersion int
 * @phpstan-type ItemTagItemDescriptor array{descriptor_type: ItemDescriptorType, item_tag: string}
 * @phpstan-type CraftCreativeStackRequestAction array{action_type: ItemStackRequestActionType, creative_item_net_id: int, number_of_requested_crafts: int}
 * @phpstan-type CraftRecipeOptionalStackRequestAction array{action_type: ItemStackRequestActionType, recipe_net_id: int, filtered_string_index: int}
 * @phpstan-type CraftRepairAndDisenchantStackRequestAction array{action_type: ItemStackRequestActionType, recipe_net_id: int, number_of_requested_crafts: int, repair_cost: int}
 * @phpstan-type CraftLoomStackRequestAction array{action_type: ItemStackRequestActionType, pattern_name_id: string, num_crafts: int}
 * @phpstan-type CraftResultsDeprecatedStackRequestAction array{action_type: ItemStackRequestActionType, craft_results: list<ItemInstance>, num_crafts: int}
 * @phpstan-type ItemInstance array{item_descriptor: ItemDescriptor, stack_size: int, block_runtime_id: int, user_data_buffer: string}
 * @phpstan-type TextProcessingEventOrigin int
 * @phpstan-type PlayerBlockAction array{player_action_type: PlayerActionType, position: BlockPosition, facing: int}
 * @phpstan-type CreativeGroup array{creative_category: CreativeItemCategory, name: string, group_icon_item: NetworkItemInstanceDescriptor}
 * @phpstan-type CreativeItemCategory int
 * @phpstan-type CreativeItemEntryPayload array{creative_net_id: int, item_instance: NetworkItemInstanceDescriptor, group_index: int}
 * @phpstan-type ItemEnchantOption array{cost: int, enchants: ItemEnchants, enchant_name: string, enchant_net_id: int}
 * @phpstan-type ItemEnchants array{slot: int, item_enchants: list<list<EnchantmentInstance>>}
 * @phpstan-type EnchantmentInstance array{enchant_type: EnchantType, enchant_level: int}
 * @phpstan-type EnchantType int
 * @phpstan-type ItemStackRequestPacketData array{client_request_id: int, actions: list<StackRequestAction>, strings_to_filter: list<string>, strings_to_filter_origin: TextProcessingEventOrigin}
 * @phpstan-type ItemStackResponseInfo array{result: ItemStackNetResult, client_request_id: int, containers: list<ItemStackResponseContainerInfo>|null}
 * @phpstan-type ItemStackNetResult int
 * @phpstan-type ItemStackResponseContainerInfo array{full_container_name: FullContainerName, slots: list<ItemStackResponseSlotInfo>}
 * @phpstan-type ItemStackResponseSlotInfo array{requested_slot: int, slot: int, amount: int, item_stack_net_id: int|null, custom_name: SafetyRedactableString, durability_correction: int}
 * @phpstan-type SafetyRedactableString array{unredacted: string, redacted: string|null}
 * @phpstan-type PlayerArmourDamageEntry array{armor_slot: LegacyArmorSlot, damage: int}
 * @phpstan-type LegacyArmorSlot int
 * @phpstan-type PositionTrackingDBServerBroadcastAction int
 * @phpstan-type PositionTrackingDBClientRequestAction int
 * @phpstan-type PacketViolationType int
 * @phpstan-type PacketViolationSeverity int
 * @phpstan-type CameraShakeType int
 * @phpstan-type CameraShakeAction int
 * @phpstan-type RewindType int
 * @phpstan-type ItemData array{item_name: string, item_id: int, is_component_based: bool, item_version: ItemVersion, item_component_data: string}
 * @phpstan-type ItemVersion int
 * @phpstan-type ClientboundDebugRendererDebugMarkerData array{text: string, position: Vec3f, color: int, duration: int}
 * @phpstan-type SimulationTypeEnum int
 * @phpstan-type NpcDialogueActionType int
 * @phpstan-type UpdateSubChunkBlocksChangedInfo array{blocks_changed_standards: list<UpdateSubChunkNetworkBlockInfo>, blocks_changed_extras: list<UpdateSubChunkNetworkBlockInfo>}
 * @phpstan-type UpdateSubChunkNetworkBlockInfo array{pos: BlockPosition, runtime_id: int, update_flags: int, sync_message_entity_unique_id: int, sync_message_message: int}
 * @phpstan-type SubChunkPosition array{x: int, y: int, z: int}
 * @phpstan-type SubChunkData array{sub_chunk_pos_offset: SubChunkPosOffset, sub_chunk_request_result: SubChunkRequestResult, serialized_sub_chunk: string|null, height_map_data: SubChunkHeightmapData, blob_id: int|null}
 * @phpstan-type SubChunkPosOffset array{subchunk_offset_x: int, subchunk_offset_y: int, subchunk_offset_z: int}
 * @phpstan-type SubChunkRequestResult int
 * @phpstan-type SubChunkHeightmapData array{height_map_type: SubChunkHeightMapDataType, subchunk_height_map: list<list<int>>|null, render_height_map_type: SubChunkHeightMapDataType, subchunk_render_height_map: list<list<int>>|null}
 * @phpstan-type SubChunkHeightMapDataType int
 * @phpstan-type CodeBuilderStorageQueryOptionsOperation int
 * @phpstan-type CodeBuilderStorageQueryOptionsCategory int
 * @phpstan-type CodeBuilderExecutionStateCodeStatus int
 * @phpstan-type PacketDefinitionsEntry array{key: string, value: DimensionDefinitionGroupDimensionDefinition}
 * @phpstan-type DimensionDefinitionGroupDimensionDefinition array{minimum_y: int, height_range: int, generator_type: GeneratorType, dimension_type: int, pack_id: string, default_biome: string}
 * @phpstan-type AgentActionType int
 * @phpstan-type RequestAbilityType int
 * @phpstan-type AdventureSettings array{no_pv_m: bool, no_mv_p: bool, immutable_world: bool, show_name_tags: bool, auto_jump: bool}
 * @phpstan-type FeatureRegistryFeatureBinaryJsonFormat array{feature_name: string, binary_json_output: string}
 * @phpstan-type CameraPreset array{name: string, inherit_from: string, pos_x: float|null, pos_y: float|null, pos_z: float|null, rot_x: float|null, rot_y: float|null, rotation_speed: float|null, snap_to_target: bool|null, horizontal_rotation_limit: Vec2f|null, vertical_rotation_limit: Vec2f|null, continue_targeting: bool|null, block_listening_radius: float|null, view_offset: Vec2f|null, entity_offset: Vec3f|null, radius: float|null, yaw_limit_min: float|null, yaw_limit_max: float|null, listener: CameraPresetAudioListener|null, player_effects: bool|null, aim_assist: CameraAimAssistCommandPresetDefinition|null, control_scheme: ControlSchemeScheme|null, apply_inherited_starting_rotation: bool, starting_rotation: Vec2f|null}
 * @phpstan-type CameraPresetAudioListener int
 * @phpstan-type CameraAimAssistCommandPresetDefinition array{preset_id: string|null, target_mode: CameraAimAssistTargetMode|null, view_angle: Vec2f|null, distance: float|null}
 * @phpstan-type CameraAimAssistTargetMode int
 * @phpstan-type ControlSchemeScheme int
 * @phpstan-type UnlockedRecipesPacketType int
 * @phpstan-type CameraInstructionData array{set: CameraInstructionSet|null, clear: bool|null, fade: CameraInstructionFade|null, target: CameraInstructionTargetData|null, remove_target: bool|null, field_of_view: CameraInstructionFieldOfView|null, spline: CameraSplineInstruction|null, attach_to_entity: int|null, detach_from_entity: bool|null}
 * @phpstan-type CameraInstructionSet array{preset: int, ease: CameraEase|null, pos: Vec3f|null, rot: CameraRotation|null, facing: Vec3f|null, view_offset: CameraViewOffset|null, entity_offset: CameraEntityOffset|null, default: bool|null, remove_ignore_starting_values_component: bool}
 * @phpstan-type CameraEase array{type: int, time: float}
 * @phpstan-type CameraRotation array{x: float, y: float}
 * @phpstan-type CameraViewOffset array{x: float, y: float}
 * @phpstan-type CameraEntityOffset array{entity_offset_x: float, entity_offset_y: float, entity_offset_z: float}
 * @phpstan-type CameraInstructionFade array{time: CameraFadeTimeData|null, color: CameraFadeColor|null}
 * @phpstan-type CameraFadeTimeData array{fade_in_time: float, hold_time: float, fade_out_time: float}
 * @phpstan-type CameraFadeColor array{red: float, green: float, blue: float}
 * @phpstan-type CameraInstructionTargetData array{target_center_offset: Vec3f|null, target_actor_id: int}
 * @phpstan-type CameraInstructionFieldOfView array{field_of_view: float, fov_ease_time: float, fov_ease_type: string, field_of_view_clear: bool}
 * @phpstan-type CameraSplineInstruction array{total_time: float, type: int, curve: list<Vec3f>, progress_key_frames: list<CameraProgressOption>, rotation_option: list<CameraRotationOption>, spline_identifier: string, load_from_json: bool}
 * @phpstan-type CameraProgressOption array{key_frame_value: float, key_frame_time: float, key_frame_easing_func: string}
 * @phpstan-type CameraRotationOption array{key_frame_value: Vec3f, key_frame_time: float, key_frame_easing_func: string}
 * @phpstan-type TrimPattern array{item_name: string, pattern_id: string}
 * @phpstan-type TrimMaterial array{material_id: string, color: string, item_name: string}
 * @phpstan-type AgentAnimationType int
 * @phpstan-type InventoryOptions array{left_inventory_tab: InventoryLeftTabIndex, right_inventory_tab: InventoryRightTabIndex, filtering: bool, layout_inv: InventoryLayout, layout_craft: InventoryLayout}
 * @phpstan-type InventoryLeftTabIndex int
 * @phpstan-type InventoryRightTabIndex int
 * @phpstan-type InventoryLayout int
 * @phpstan-type HudElement int
 * @phpstan-type HudVisibility int
 * @phpstan-type ServerboundLoadingScreenType int
 * @phpstan-type MemoryCategoryCounter array{category: MemoryCategory, current_bytes: int}
 * @phpstan-type MemoryCategory int
 * @phpstan-type EntityDiagnosticTimingInfo array{display_name: string, entity: string, time_in_ns: int, percent_of_total: int, position: Vec3f|null, dimension: string|null}
 * @phpstan-type SystemDiagnosticTimingInfo array{display_name: string, system_index: int, time_in_ns: int, percent_of_total: int}
 * @phpstan-type SystemCategory array{category_name: string, system_index: int}
 * @phpstan-type WhiskerScopeDataSummary array{label: string, indentation: string, total_high_cost_ns: int, total_mid_cost_ns: int, total_low_cost_ns: int}
 * @phpstan-type CameraAimAssistPacketTargetMode int
 * @phpstan-type CameraAimAssistAction int
 * @phpstan-type MovementEffectType int
 * @phpstan-type CameraAimAssistCategory array{name: string, priorities: CameraAimAssistPriorities}
 * @phpstan-type CameraAimAssistPriorities array{entities: list<CameraAimAssistPrioritiesEntitiesEntry>, blocks: list<CameraAimAssistPrioritiesBlocksEntry>, block_tags: list<CameraAimAssistPrioritiesBlockTagsEntry>, entity_type_families: list<CameraAimAssistPrioritiesEntityTypeFamiliesEntry>, entity_default: int|null, block_default: int|null}
 * @phpstan-type CameraAimAssistPrioritiesEntitiesEntry array{key: string, value: int}
 * @phpstan-type CameraAimAssistPrioritiesBlocksEntry array{key: string, value: int}
 * @phpstan-type CameraAimAssistPrioritiesBlockTagsEntry array{key: string, value: int}
 * @phpstan-type CameraAimAssistPrioritiesEntityTypeFamiliesEntry array{key: string, value: int}
 * @phpstan-type CameraAimAssistPresetDefinition array{identifier: string, exclusion_settings: CameraAimAssistPresetExclusionDefinition, liquid_targeting_list: list<string>, item_settings: list<CameraAimAssistPresetDefinitionItemSettingsEntry>, default_item_settings: string|null, hand_settings: string|null}
 * @phpstan-type CameraAimAssistPresetExclusionDefinition array{blocks: list<string>, entities: list<string>, block_tags: list<string>, entity_type_families: list<string>}
 * @phpstan-type CameraAimAssistPresetDefinitionItemSettingsEntry array{key: string, value: string}
 * @phpstan-type CameraAimAssistPresetOperation int
 * @phpstan-type ClientCameraAimAssistAction int
 * @phpstan-type GraphicsMode int
 * @phpstan-type PlayerVideoCaptureAction array{start: PlayerVideoCaptureStartVideoCapture}|array{stop: PlayerVideoCaptureStopVideoCapture}
 * @phpstan-type PlayerVideoCaptureStartVideoCapture array{frame_rate: int, file_prefix: string}
 * @phpstan-type PlayerVideoCaptureStopVideoCapture array{}
 * @phpstan-type PlayerUpdateEntityOverridesUpdate array{clear: string}|array{remove: string}|array{int: IntOverride}|array{float: FloatOverride}
 * @phpstan-type IntOverride array{type: string, value: int}
 * @phpstan-type FloatOverride array{type: string, value: float}
 * @phpstan-type PlayerLocationLocation array{coordinates: PlayerLocationCoordinatesLocation}|array{hidden: PlayerLocationType}
 * @phpstan-type PlayerLocationCoordinatesLocation array{packet_type: PlayerLocationType, position: Vec3f}
 * @phpstan-type PlayerLocationType int
 * @phpstan-type PrimitiveShape array{network_id: int, shape_type: ScriptModuleMinecraftScriptPrimitiveShapeType|null, location: Vec3f|null, scale: float|null, rotation: Vec3f|null, total_time_left: float|null, maximum_render_distance: float|null, color: int|null, dimension_id: int|null, attached_to_entity_id: int|null, extra_shape_data: PrimitiveShapeExtraShapeData}
 * @phpstan-type ScriptModuleMinecraftScriptPrimitiveShapeType int
 * @phpstan-type PrimitiveShapeExtraShapeData array{empty: null}|array{arrow: ArrowShape}|array{text: TextShape}|array{box: Vec3f}|array{line: Vec3f}|array{sphere: int}|array{cylinder: CylinderShape}|array{pyramid: PyramidShape}|array{ellipsoid: EllipsoidShape}|array{cone: ConeShape}
 * @phpstan-type ArrowShape array{arrow_end_location: Vec3f|null, arrow_head_length: float|null, arrow_head_radius: float|null, num_segments: int|null}
 * @phpstan-type TextShape array{text: string, use_rotation: bool, background_color: int|null, line_gap_height: float, depth_test: bool, show_backface: bool, show_text_backface: bool}
 * @phpstan-type CylinderShape array{radius_x: Vec2f, radius_z: Vec2f, height: float, num_segments: int}
 * @phpstan-type PyramidShape array{width: float, depth: float|null, height: float}
 * @phpstan-type EllipsoidShape array{radii: Vec3f, segments_per_axis: int}
 * @phpstan-type ConeShape array{radii: Vec2f, height: float, num_segments: int}
 * @phpstan-type ServerBoundPackSettingChangePackSettingValue array{float: float}|array{bool: bool}|array{string: string}|array{string_list: list<string>}
 * @phpstan-type ClientBoundDataStoreUpdatesItem array{update: DduiDataStoreUpdate}|array{change: DataStoreChange}|array{removal: string}
 * @phpstan-type DduiDataStoreUpdate array{data_store_name: string, property: string, path: string, data: DduiDataStoreUpdateData, property_update_count: int, path_update_count: int}
 * @phpstan-type DduiDataStoreUpdateData array{double: float}|array{bool: bool}|array{string: string}
 * @phpstan-type DataStoreChange array{data_store_name: string, property: string, update_count: int, the_new_property_value: array<mixed>}
 * @phpstan-type PacketParameterKeyframeValuesEntry array{key: float, value: Vec3f}
 * @phpstan-type GraphicsOverrideParameterType int
 * @phpstan-type ClientboundTextureShiftAction int
 * @phpstan-type VoxelShape array{cells: VoxelCells, x_coordinates: list<float>, y_coordinates: list<float>, z_coordinates: list<float>}
 * @phpstan-type VoxelCells array{x_size: int, y_size: int, z_size: int, storage: string}
 * @phpstan-type PacketNameMapEntry array{key: string, value: int}
 * @phpstan-type CameraSplineDefinition array{name: string, total_time: float, spline_type: string, control_points: list<Vec3f>, progress_key_frames: list<CameraSplineProgressKeyFrame>, rotation_key_frames: list<CameraSplineRotationKeyFrame>}
 * @phpstan-type CameraSplineProgressKeyFrame array{progress: float, time: float, easing: string|null}
 * @phpstan-type CameraSplineRotationKeyFrame array{rotation: Vec3f, time: float, easing: string|null}
 * @phpstan-type CameraAimAssistActorPriorityPriorityData array{preset_index: int, category_index: int, actor_index: int, priority_value: int}
 * @phpstan-type LocatorBarWaypointPayload array{group_handle: string, server_waypoint_payload: Waypoint, action_flag: ServerWaypointGroupAction}
 * @phpstan-type Waypoint array{update_flag: int, is_visible: bool|null, world_position: WorldPosition|null, texture_path: string|null, icon_size: Vec2f|null, color: int|null, client_position_authority: bool|null, actor_unique_id: int|null}
 * @phpstan-type WorldPosition array{position: Vec3f, dimension_type: int}
 * @phpstan-type ServerWaypointGroupAction int
 * @phpstan-type PlayerPartyInfo array{party_id: string, is_party_leader: bool}
 * @phpstan-type SyncWorldClocksData array{sync_state: list<SyncWorldClockStateData>}|array{initialize_registry: list<WorldClockData>}|array{add_time_marker: SyncWorldClocksAddTimeMarkerData}|array{remove_time_marker: SyncWorldClocksRemoveTimeMarkerData}
 * @phpstan-type SyncWorldClockStateData array{clock_id: int, time: int, is_paused: bool}
 * @phpstan-type WorldClockData array{id: int, name: string, time: int, is_paused: bool, time_markers: list<TimeMarkerData>}
 * @phpstan-type TimeMarkerData array{id: int, name: string, time: int, period: int|null}
 * @phpstan-type SyncWorldClocksAddTimeMarkerData array{clock_id: int, time_markers: list<TimeMarkerData>}
 * @phpstan-type SyncWorldClocksRemoveTimeMarkerData array{clock_id: int, time_marker_ids: list<int>}
 * @phpstan-type ClientBoundAttributeLayerSyncData array{update_attribute_layers: list<EasAttributeLayerData>}|array{update_attribute_layer_settings: AttributeLayerSettings}|array{update_environment_attributes: EnvironmentAttributeData}|array{remove_environment_attributes: RemoveEnvironmentAttributes}
 * @phpstan-type EasAttributeLayerData array{name: string, noise_name: string|null, dimension: int, settings: EasAttributeLayerSettings, attributes: list<EasEnvironmentAttributeData>}
 * @phpstan-type EasAttributeLayerSettings array{priority: int, weight: float, enabled: bool, transitions_paused: bool}
 * @phpstan-type EasEnvironmentAttributeData array{attribute_name: string, from_attribute: EnvironmentAttributeValue|null, attribute: EnvironmentAttributeValue, to_attribute: EnvironmentAttributeValue|null, current_transition_ticks: int, total_transition_ticks: int, easing: string, local_transition_ticks: int, noise_transition: bool, noise_alignment: NoiseAlignment}
 * @phpstan-type EnvironmentAttributeValue array{bool: EASBoolAttributeData}|array{float: EASFloatAttributeData}|array{color: EASColorAttributeData}
 * @phpstan-type EASBoolAttributeData array{value: bool, operation: string}
 * @phpstan-type EASFloatAttributeData array{value: float, operation: string, constraint_min: float|null, constraint_max: float|null}
 * @phpstan-type EASColorAttributeData array{value: list<int>, operation: string}
 * @phpstan-type NoiseAlignment array{type: EasNoiseAlignmentType, value: int}
 * @phpstan-type EasNoiseAlignmentType int
 * @phpstan-type AttributeLayerSettings array{attribute_layer_name: string, attribute_layer_dimension: int, attributes_layer_settings: EasAttributeLayerSettings}
 * @phpstan-type EnvironmentAttributeData array{attribute_layer_name: string, attribute_layer_dimension: int, attributes: list<EasEnvironmentAttributeData>}
 * @phpstan-type RemoveEnvironmentAttributes array{attribute_layer_name: string, attribute_layer_dimension: int, attributes: list<string>}
 * @phpstan-type SoundDataEvent array{stop: SoundDataEventStop}|array{set_volume: float}|array{set_pitch: float}|array{fade: SoundDataEventFade}|array{seek_to: float}|array{pause: SoundDataEventPause}|array{resume: SoundDataEventResume}
 * @phpstan-type SoundDataEventStop array{}
 * @phpstan-type SoundDataEventFade array{duration: float, target_volume: float}
 * @phpstan-type SoundDataEventPause array{}
 * @phpstan-type SoundDataEventResume array{}
 * @phpstan-type SetPlayerFurnaceOptionsFurnaceType int
 * @phpstan-type FurnaceOptions array{left_furnace_tab: FurnaceLeftTabIndex, filtering: bool, layout: FurnaceLayout}
 * @phpstan-type FurnaceLeftTabIndex int
 * @phpstan-type FurnaceLayout int
 * @phpstan-type LoginFields array{client_network_version: int, connection_request: string}
 * @phpstan-type PlayStatusFields array{status: PlayStatusType}
 * @phpstan-type ServerToClientHandshakeFields array{handshake_web_token: string}
 * @phpstan-type ClientToServerHandshakeFields array{}
 * @phpstan-type DisconnectFields array{reason: ConnectionDisconnectFailReason, messages: DisconnectMessages}
 * @phpstan-type ResourcePacksInfoFields array{resource_pack_required: bool, has_addon_packs: bool, has_scripts: bool, force_disable_vibrant_visuals: bool, world_template_id_and_version: PackIDVersionData, resource_packs: list<PackInfoData>}
 * @phpstan-type ResourcePackStackFields array{texture_pack_required: bool, texture_pack_list: list<StackResourcePack>, base_game_version: string, experiments: Experiments, include_editor_packs: bool}
 * @phpstan-type ResourcePackClientResponseFields array{response: ResourcePackClientResponseResponse}
 * @phpstan-type TextFields array{localize: bool, body: TextBody, senders_xuid: string, platform_id: string, filtered_message: string|null}
 * @phpstan-type SetTimeFields array{time: int}
 * @phpstan-type StartGameFields array{entity_id: int, runtime_id: int, game_type: GameType, position: Vec3f, rotation: Vec2f, settings: LevelSettings, level_id: string, level_name: string, template_content_identity: string, is_trial: bool, movement_settings: PlayerMovementSettings, level_current_time: int, enchantment_seed: int, block_properties: list<ServerBlockProperty>, multiplayer_correlation_id: string, enable_item_stack_net_manager: bool, server_version: string, player_property_data: string, server_block_type_registry_checksum: int, world_template_id: string, server_enabled_client_side_generation: bool, block_network_ids_are_hashes: bool, network_permissions: bool, server_configuration_join_info: ServerConfigurationJoinInfo|null, server_telemetry_data: EventsServerTelemetryData}
 * @phpstan-type AddPlayerFields array{uuid: string, player_name: string, target_runtime_id: int, platform_chat_id: string, position: Vec3f, velocity: Vec3f, rotation: Vec2f, y_head_rotation: float, carried_item: NetworkItemStackDescriptor, player_game_type: GameType, entity_data: list<DataItemEntry>, synched_properties: PropertySyncData, abilities_data: AbilityData, actor_links: list<EntityLink>, device_id: string, build_platform: BuildPlatform}
 * @phpstan-type AddActorFields array{target_actor_id: int, target_runtime_id: int, actor_type: string, position: Vec3f, velocity: Vec3f, rotation: Vec2f, y_head_rotation: float, y_body_rotation: float, attributes_list: list<SyncedAttribute>, actor_data: list<DataItemEntry>, synched_properties: PropertySyncData, actor_links: list<EntityLink>}
 * @phpstan-type RemoveActorFields array{target_actor_id: int}
 * @phpstan-type AddItemActorFields array{target_actor_id: int, target_runtime_id: int, item: NetworkItemStackDescriptor, position: Vec3f, velocity: Vec3f, entity_data: list<DataItemEntry>, is_from_fishing: bool}
 * @phpstan-type ServerPlayerPostMovePositionFields array{pos: Vec3f}
 * @phpstan-type TakeItemActorFields array{item_runtime_id: int, actor_runtime_id: int}
 * @phpstan-type MoveActorAbsoluteFields array{move_data: MoveActorAbsoluteData}
 * @phpstan-type MovePlayerFields array{player_runtime_id: int, position: Vec3f, rotation: Vec2f, y_head_rotation: float, position_mode: PlayerPositionModeComponentPositionMode, on_ground: bool, riding_runtime_id: int, teleport_data: MovePlayerTeleportData|null, tick: int}
 * @phpstan-type UpdateBlockFields array{block_position: BlockPosition, block_runtime_id: int, flags: int, layer: int}
 * @phpstan-type AddPaintingFields array{target_actor_id: int, target_runtime_id: int, position: Vec3f, direction: int, motif: string}
 * @phpstan-type LevelEventFields array{event_id: int, position: Vec3f, data: int}
 * @phpstan-type BlockEventFields array{block_position: BlockPosition, event_type: int, event_value: int}
 * @phpstan-type ActorEventFields array{target_runtime_id: int, event_id: ActorEventType, data: int, fire_at_position: Vec3f|null}
 * @phpstan-type MobEffectFields array{target_runtime_id: int, event_id: MobEffectEvent, effect_id: int, effect_amplifier: int, show_particles: bool, effect_duration_ticks: int, tick: int, ambient: bool}
 * @phpstan-type UpdateAttributesFields array{target_runtime_id: int, attribute_list: list<AttributeData>, tick: int}
 * @phpstan-type InventoryTransactionFields array{legacy_request_id: int, legacy_set_item_slots: list<LegacySetSlot>|null, transaction: InventoryTransactionTransaction}
 * @phpstan-type MobEquipmentFields array{target_runtime_id: int, item: NetworkItemStackDescriptor, slot: int, selected_slot: int, container_id: int}
 * @phpstan-type MobArmourEquipmentFields array{target_runtime_id: int, head: NetworkItemStackDescriptor, torso: NetworkItemStackDescriptor, legs: NetworkItemStackDescriptor, feet: NetworkItemStackDescriptor, body: NetworkItemStackDescriptor}
 * @phpstan-type InteractFields array{action: InteractAction, target_runtime_id: int, position: Vec3f|null}
 * @phpstan-type BlockPickRequestFields array{position: BlockPosition, with_data: bool, max_slots: int}
 * @phpstan-type ActorPickRequestFields array{actor_id: int, max_slots: int, with_data: bool}
 * @phpstan-type PlayerActionFields array{player_runtime_id: int, action: PlayerActionType, block_position: BlockPosition, result_pos: BlockPosition, face: int}
 * @phpstan-type HurtArmourFields array{cause: int, damage: int, armor_slots: int}
 * @phpstan-type SetActorDataFields array{target_runtime_id: int, actor_data: list<DataItemEntry>, synched_properties: PropertySyncData, tick: int}
 * @phpstan-type SetActorMotionFields array{target_runtime_id: int, motion: Vec3f, tick: int}
 * @phpstan-type SetActorLinkFields array{link: EntityLink}
 * @phpstan-type SetHealthFields array{health: int}
 * @phpstan-type SetSpawnPositionFields array{spawn_position_type: SpawnPositionType, block_position: BlockPosition, dimension_type: int, spawn_block_pos: BlockPosition}
 * @phpstan-type AnimateFields array{action: AnimateAction, target_actor_runtime_id: int, data: float, swing_source: string|null}
 * @phpstan-type RespawnFields array{position: Vec3f, state: PlayerRespawnState, player_runtime_id: int}
 * @phpstan-type ContainerOpenFields array{container_id: int, container_type: int, position: BlockPosition, target_actor_id: int}
 * @phpstan-type ContainerCloseFields array{container_id: int, container_type: int, server_initiated_close: bool}
 * @phpstan-type PlayerHotBarFields array{selected_slot: int, container_id: int, should_select_slot: bool}
 * @phpstan-type InventoryContentFields array{container_id: int, slots: list<NetworkItemStackDescriptor>, full_container_name: FullContainerName, storage_item: NetworkItemStackDescriptor}
 * @phpstan-type InventorySlotFields array{container_id: int, slot: int, full_container_name: FullContainerName|null, storage_item: NetworkItemStackDescriptor|null, item: NetworkItemStackDescriptor}
 * @phpstan-type ContainerSetDataFields array{container_id: int, id: int, value: int}
 * @phpstan-type CraftingDataFields array{shaped_recipes: list<ShapedRecipePayload>, shapeless_recipes: list<ShapelessRecipePayload>, multi_recipes: list<MultiRecipePayload>, user_data_shapeless_recipes: list<ShapelessRecipePayload>, shapeless_chemistry_recipes: list<ShapelessRecipePayload>, shaped_chemistry_recipes: list<ShapedRecipePayload>, smithing_transform_recipes: list<SmithingTransformRecipePayload>, smithing_trim_recipes: list<SmithingTrimRecipePayload>, potion_mixes: list<PotionMixDataEntry>, container_mixes: list<ContainerMixDataEntry>, material_reducers: list<MaterialReducerDataEntry>, clear_recipes: bool}
 * @phpstan-type GuiDataPickItemFields array{item_name: string, item_effect_name: string, slot: int}
 * @phpstan-type BlockActorDataFields array{block_position: BlockPosition, actor_data_tags: string}
 * @phpstan-type LevelChunkFields array{chunk_position: ChunkPosition, dimension_id: int, sub_chunks_count: int, client_request_sub_chunk_limit: int|null, cache_enabled: bool, cache_metadata: list<int>, serialized_chunk_data: string}
 * @phpstan-type SetCommandsEnabledFields array{commands_enabled: bool}
 * @phpstan-type SetDifficultyFields array{difficulty: int}
 * @phpstan-type ChangeDimensionFields array{dimension_id: int, position: Vec3f, respawn: bool, loading_screen_id: int|null}
 * @phpstan-type SetPlayerGameTypeFields array{player_game_type: GameType}
 * @phpstan-type PlayerListFields array{entries: list<PlayerListEntriesItem>}
 * @phpstan-type SimpleEventFields array{type: SimpleEventSubtype}
 * @phpstan-type EventFields array{target_actor_id: int, event_type: LegacyTelemetryType, use_player_id: bool, event_data: EventEventData}
 * @phpstan-type SpawnExperienceOrbFields array{position: Vec3f, xp_value: int}
 * @phpstan-type ClientBoundMapItemDataFields array{map_id: int, dimension: int, is_locked: bool, map_origin: BlockPosition, creation_map_i_ds: list<int>|null, scale: int|null, tracked_actor_i_ds: list<MapTrackedObject>|null, decorations: list<MapDecoration>|null, width: int|null, height: int|null, start_x: int|null, start_y: int|null, pixels: list<int>|null}
 * @phpstan-type MapInfoRequestFields array{map_unique_id: int, client_pixels_list: list<PixelRequest>}
 * @phpstan-type RequestChunkRadiusFields array{chunk_radius: int, max_chunk_radius: int}
 * @phpstan-type ChunkRadiusUpdatedFields array{chunk_radius: int}
 * @phpstan-type GameRulesChangedFields array{rule_data: list<GameRule>}
 * @phpstan-type CameraFields array{camera_id: int, target_player_id: int}
 * @phpstan-type BossEventFields array{target_actor_id: int, event_type: BossEventUpdateType, name: string, filtered_name: string, health_percent: float, color: BossBarColor, overlay: BossBarOverlay}
 * @phpstan-type ShowCreditsFields array{player_runtime_id: int, credits_state: int}
 * @phpstan-type AvailableCommandsFields array{enum_values: list<string>, chained_subcommand_values: list<string>, post_fixes: list<string>, enum_data: list<CommandEnum>, chained_subcommand_data: list<ChainedSubcommand>, commands: list<Command>, soft_enums: list<DynamicEnum>, constraints: list<CommandEnumConstraint>}
 * @phpstan-type CommandRequestFields array{command: string, origin: CommandOrigin, is_internal: bool, version: string}
 * @phpstan-type CommandBlockUpdateFields array{target: CommandBlockUpdateTarget, command: string, last_output: string, name: string, filtered_name: string, track_output: bool, tick_delay: int, execute_on_first_tick: bool}
 * @phpstan-type CommandOutputFields array{origin_data: CommandOrigin, output: CommandOutputData}
 * @phpstan-type UpdateTradeFields array{container_id: int, type: int, size: int, trader_tier: int, entity_unique_id: int, last_trading_player: int, display_name: string, use_new_trade_screen: bool, using_economy_trade: bool, data: string}
 * @phpstan-type UpdateEquipFields array{container_id: int, type: int, size: int, entity_unique_id: int, data: string}
 * @phpstan-type ResourcePackDataInfoFields array{resource_name: string, chunk_size: int, number_of_chunks: int, file_size: int, file_hash: string, is_premium_pack: bool, pack_type: int}
 * @phpstan-type ResourcePackChunkDataFields array{resource_name: string, chunk_id: int, byte_offset: int, chunk_data: string}
 * @phpstan-type ResourcePackChunkRequestFields array{resource_name: string, chunk: int}
 * @phpstan-type TransferFields array{server_address: string, server_port: int, reload_world: bool, gatherings_configuration: GatheringJoinInfo|null}
 * @phpstan-type PlaySoundFields array{name: string, position: BlockPosition, volume: float, pitch: float, loop_count: int, bypass_listener_range_check: bool, server_sound_handle: int|null, playback_position_seconds: float|null}
 * @phpstan-type StopSoundFields array{sound_name: string, stop_all_sounds: bool, stop_music_legacy: bool}
 * @phpstan-type SetTitleFields array{title_type: SetTitleTitleType, title_text: string, fade_in_time: int, stay_time: int, fade_out_time: int, xuid: string, platform_online_id: string, filtered_title_message: string}
 * @phpstan-type AddBehaviourTreeFields array{behavior_tree_structure_json: string}
 * @phpstan-type StructureBlockUpdateFields array{block_position: BlockPosition, structure_data: StructureEditorData, trigger: bool, is_waterlogged: bool}
 * @phpstan-type ShowStoreOfferFields array{offer_id: string, redirect_type: ShowStoreOfferRedirectType}
 * @phpstan-type PurchaseReceiptFields array{purchase_receipts: list<string>}
 * @phpstan-type PlayerSkinFields array{uuid: string, serialized_skin: SerializedSkinRef, localized_new_skin_name: string, localized_old_skin_name: string}
 * @phpstan-type SubClientLoginFields array{sub_client_connection_request: string}
 * @phpstan-type AutomationClientConnectFields array{web_socket_data: string}
 * @phpstan-type SetLastHurtByFields array{last_hurt_by: ActorType}
 * @phpstan-type BookEditFields array{book_slot: int, operation: BookEditOperation}
 * @phpstan-type NpcRequestFields array{npc_runtime_id: int, request_type: NpcRequestRequestType, actions: string, action_index: int, scene_name: string}
 * @phpstan-type PhotoTransferFields array{photo_name: string, photo_data: string, book_id: string, type: PhotoType, source_type: PhotoType, owner_id: int, new_photo_name: string}
 * @phpstan-type ModalFormRequestFields array{form_id: int, form_ui_json: string}
 * @phpstan-type ModalFormResponseFields array{form_id: int, json_response: string|null, form_cancel_reason: ModalFormCancelReason|null}
 * @phpstan-type ServerSettingsRequestFields array{}
 * @phpstan-type ServerSettingsResponseFields array{form_id: int, form_ui_json: string}
 * @phpstan-type ShowProfileFields array{player_xuid: string}
 * @phpstan-type SetDefaultGameTypeFields array{default_game_type: GameType}
 * @phpstan-type RemoveObjectiveFields array{objective_name: string}
 * @phpstan-type SetDisplayObjectiveFields array{display_slot_name: string, objective_name: string, objective_display_name: string, criteria_name: string, sort_order: int}
 * @phpstan-type SetScoreFields array{score_info: list<SetScoreScoreInfoItem>}
 * @phpstan-type LabTableFields array{type: LabTableType, position: BlockPosition, reaction: LabTableReactionType}
 * @phpstan-type UpdateBlockSyncedFields array{block_position: BlockPosition, block_runtime_id: int, flags: int, layer: int, unique_actor_id: int, actor_sync_message: int}
 * @phpstan-type MoveActorDeltaFields array{move_data: MoveActorDeltaData}
 * @phpstan-type SetScoreboardIdentityFields array{scoreboard_identity_packet_type: ScoreboardIdentityPacketType, scoreboard_identity_info: list<ScoreboardIdentityPacketInfo>}
 * @phpstan-type SetLocalPlayerAsInitialisedFields array{player_id: int}
 * @phpstan-type UpdateSoftEnumFields array{enum_name: string, values: list<string>, update_type: SoftEnumUpdateType}
 * @phpstan-type NetworkStackLatencyFields array{creation_time: int, is_from_server: bool}
 * @phpstan-type SpawnParticleEffectFields array{dimension_id: int, actor_id: int, position: Vec3f, effect_name: string, molang_variables: string|null}
 * @phpstan-type AvailableActorIdentifiersFields array{identifier_list: string}
 * @phpstan-type NetworkChunkPublisherUpdateFields array{new_position_for_view: BlockPosition, new_radius_for_view: int, server_built_chunks_list: list<ChunkPosition>}
 * @phpstan-type BiomeDefinitionListFields array{map_of_biome_names_to_data: list<PacketMapOfBiomeNamesToDataEntry>, string_list: list<string>}
 * @phpstan-type LevelSoundEventFields array{sound_event: string, position: Vec3f, data: int, actor_identifier: string, is_baby: bool, is_global: bool, actor_unique_id: int, fire_at_position: Vec3f|null}
 * @phpstan-type LevelEventGenericFields array{event_id: int, ctd: string}
 * @phpstan-type LecternUpdateFields array{new_page_to_show: int, total_pages: int, position_of_lectern_to_update: BlockPosition}
 * @phpstan-type ClientCacheStatusFields array{is_cache_supported: bool}
 * @phpstan-type OnScreenTextureAnimationFields array{effect_id: int}
 * @phpstan-type MapCreateLockedCopyFields array{original_map_id: int, new_map_id: int}
 * @phpstan-type StructureTemplateDataRequestFields array{structure_name: string, structure_position: BlockPosition, structure_settings: StructureSettings, requested_operation: StructureTemplateRequestOperation}
 * @phpstan-type StructureTemplateDataResponseFields array{structure_name: string, structures_nbt: string, response_type: StructureTemplateResponseType}
 * @phpstan-type ClientCacheBlobStatusFields array{missing_ids: list<int>, found_ids: list<int>}
 * @phpstan-type ClientCacheMissResponseFields array{missing_blobs: list<MissingBlobData>}
 * @phpstan-type EducationSettingsFields array{education_level_settings: EducationLevelSettings}
 * @phpstan-type EmoteFields array{actor_runtime_id: int, emote_id: string, emote_length_ticks: int, xuid: string, platform_id: string, flags: int}
 * @phpstan-type MultiPlayerSettingsFields array{packet_type: MultiplayerSettingsType}
 * @phpstan-type SettingsCommandFields array{command: string, suppress_output: bool}
 * @phpstan-type AnvilDamageFields array{block_position: BlockPosition}
 * @phpstan-type CompletedUsingItemFields array{item_id: int, item_use_method: int}
 * @phpstan-type NetworkSettingsFields array{compression_threshold: int, compression_algorithm: PacketCompressionAlgorithm, client_throttle_enabled: bool, client_throttle_threshold: int, client_throttle_scalar: float}
 * @phpstan-type PlayerAuthInputFields array{player_rotation: Vec2f, position: Vec3f, move_vector: Vec2f, player_head_rotation: float, input_data: list<PlayerAuthInputInputData>, input_mode: InputMode, play_mode: ClientPlayMode, new_interaction_model: NewInteractionModel, interact_rotation: Vec2f, client_tick: int, pos_delta: Vec3f, item_use_transaction: PackedItemUseLegacyInventoryTransaction|null, item_stack_request: ItemStackRequestData|null, player_block_actions: list<PlayerBlockAction>|null, vehicle_rotation: Vec2f|null, client_predicted_vehicle: int|null, analog_move_vector: Vec2f, camera_orientation: Vec3f, raw_move_vector: Vec2f}
 * @phpstan-type CreativeContentFields array{groups: list<CreativeGroup>, entries: list<CreativeItemEntryPayload>}
 * @phpstan-type PlayerEnchantOptionsFields array{options: list<ItemEnchantOption>}
 * @phpstan-type ItemStackRequestFields array{requests: list<ItemStackRequestPacketData>}
 * @phpstan-type ItemStackResponseFields array{responses: list<ItemStackResponseInfo>}
 * @phpstan-type PlayerArmourDamageFields array{armor_slot_and_damage_pairs: list<PlayerArmourDamageEntry>}
 * @phpstan-type CodeBuilderFields array{url: string, should_open_code_builder: bool}
 * @phpstan-type UpdatePlayerGameTypeFields array{player_game_type: GameType, target_player: int, tick: int}
 * @phpstan-type EmoteListFields array{runtime_id: int, emote_piece_ids: list<string>}
 * @phpstan-type PositionTrackingDbServerBroadcastFields array{action: PositionTrackingDBServerBroadcastAction, id: int, position_tracking_data: string}
 * @phpstan-type PositionTrackingDbClientRequestFields array{action: PositionTrackingDBClientRequestAction, id: int}
 * @phpstan-type DebugInfoFields array{actor_id: int, data: string}
 * @phpstan-type PacketViolationWarningFields array{violation_type: PacketViolationType, violation_severity: PacketViolationSeverity, violation_packet_id: int, violation_context: string}
 * @phpstan-type MotionPredictionHintsFields array{m_runtime_id: int, m_motion: Vec3f, m_on_ground: bool}
 * @phpstan-type AnimateEntityFields array{m_animation: string, m_next_state: string, m_stop_expression: string, m_stop_expression_version: int, m_controller: string, m_blend_out_time: float, m_runtime_ids: list<int>}
 * @phpstan-type CameraShakeFields array{intensity: float, seconds: float, shake_type: CameraShakeType, shake_action: CameraShakeAction}
 * @phpstan-type PlayerFogFields array{fog_stack: list<string>}
 * @phpstan-type CorrectPlayerMovePredictionFields array{prediction_type: RewindType, pos: Vec3f, pos_delta: Vec3f, rotation: Vec2f, vehicle_angular_velocity: float|null, on_ground: bool, tick: int}
 * @phpstan-type ItemRegistryFields array{item_data: list<ItemData>}
 * @phpstan-type ClientBoundDebugRendererFields array{type: string, debug_marker_data: ClientboundDebugRendererDebugMarkerData|null}
 * @phpstan-type SyncActorPropertyFields array{property_data: string}
 * @phpstan-type AddVolumeEntityFields array{entity_network_id: int, components: string, json_identifier: string, instance_name: string, min_bounds: BlockPosition, max_bounds: BlockPosition, dimension_type: int, engine_version: string}
 * @phpstan-type RemoveVolumeEntityFields array{entity_network_id: int, dimension_type: int}
 * @phpstan-type SimulationTypeFields array{sim_type: SimulationTypeEnum}
 * @phpstan-type NpcDialogueFields array{npc_id_raw_id: int, npc_dialogue_action_type: NpcDialogueActionType, dialogue: string, scene_name: string, npc_name: string, action_json: string}
 * @phpstan-type EducationResourceUriFields array{edu_shared_uri_resource: EducationSharedResourceURI}
 * @phpstan-type CreatePhotoFields array{raw_id: int, photo_name: string, photo_item_name: string}
 * @phpstan-type UpdateSubChunkBlocksFields array{sub_chunk_block_position: BlockPosition, blocks_changed: UpdateSubChunkBlocksChangedInfo}
 * @phpstan-type SubChunkFields array{cache_enabled: bool, dimension_type: int, center_pos: SubChunkPosition, sub_chunk_data: list<SubChunkData>}
 * @phpstan-type SubChunkRequestFields array{dimension_type: int, sub_chunk_position_offset_list: list<SubChunkPosOffset>, center_pos: SubChunkPosition}
 * @phpstan-type ClientStartItemCooldownFields array{item_category: string, duration_ticks: int}
 * @phpstan-type ScriptMessageFields array{message_id: string, message_value: string}
 * @phpstan-type CodeBuilderSourceFields array{operation: CodeBuilderStorageQueryOptionsOperation, category: CodeBuilderStorageQueryOptionsCategory, code_status: CodeBuilderExecutionStateCodeStatus}
 * @phpstan-type TickingAreasLoadStatusFields array{waiting_for_preload: bool}
 * @phpstan-type DimensionDataFields array{definitions: list<PacketDefinitionsEntry>}
 * @phpstan-type AgentActionFields array{request_id: string, action: AgentActionType, response: string}
 * @phpstan-type ChangeMobPropertyFields array{actor_id: int, property_name: string, bool_component_value: bool, string_component_value: string, int_component_value: int, float_component_value: float}
 * @phpstan-type LessonProgressFields array{lesson_action: int, score: int, activity_id: string}
 * @phpstan-type RequestAbilityFields array{ability: int, value_type: RequestAbilityType, bool: bool, float: float}
 * @phpstan-type RequestPermissionsFields array{target_player_ids_raw_id: int, player_permission_level: int, custom_permission_flags: int}
 * @phpstan-type ToastRequestFields array{title: string, content: string}
 * @phpstan-type UpdateAbilitiesFields array{data: AbilityData}
 * @phpstan-type UpdateAdventureSettingsFields array{adventure_settings: AdventureSettings}
 * @phpstan-type DeathInfoFields array{death_cause_attack_name: string, death_cause_message_list: list<string>}
 * @phpstan-type EditorNetworkFields array{route_to_manager: bool, payload: string}
 * @phpstan-type FeatureRegistryFields array{features_data_list: list<FeatureRegistryFeatureBinaryJsonFormat>}
 * @phpstan-type ServerStatsFields array{server_time: float, network_time: float}
 * @phpstan-type RequestNetworkSettingsFields array{client_network_version: int}
 * @phpstan-type GameTestRequestFields array{max_tests_per_batch: int, repeat_count: int, rotation: Rotation, stop_on_failure: bool, test_pos: BlockPosition, tests_per_row: int, test_name: string}
 * @phpstan-type GameTestResultsFields array{succeeded: bool, error: string, test_name: string}
 * @phpstan-type UpdateClientInputLocksFields array{input_lock_component_data: int}
 * @phpstan-type CameraPresetsFields array{camera_presets: list<CameraPreset>}
 * @phpstan-type UnlockedRecipesFields array{packet_type: UnlockedRecipesPacketType, unlocked_recipes_list: list<string>}
 * @phpstan-type CameraInstructionFields array{camera_instruction: CameraInstructionData}
 * @phpstan-type TrimDataFields array{trim_pattern_list: list<TrimPattern>, trim_material_list: list<TrimMaterial>}
 * @phpstan-type OpenSignFields array{pos: BlockPosition, is_front_side: bool}
 * @phpstan-type AgentAnimationFields array{agent_animation: AgentAnimationType, runtime_id: int}
 * @phpstan-type RefreshEntitlementsFields array{}
 * @phpstan-type PlayerToggleCrafterSlotRequestFields array{pos_x: int, pos_y: int, pos_z: int, slot_index: int, is_disabled: bool}
 * @phpstan-type SetPlayerInventoryOptionsFields array{inventory_options: InventoryOptions}
 * @phpstan-type SetHudFields array{hud_element: list<HudElement>, hud_visible: HudVisibility}
 * @phpstan-type AwardAchievementFields array{achievement_id: int}
 * @phpstan-type ClientBoundCloseFormFields array{}
 * @phpstan-type ServerBoundLoadingScreenFields array{loading_screen_packet_type: ServerboundLoadingScreenType, loading_screen_id: int|null}
 * @phpstan-type JigsawStructureDataFields array{jigsaw_structure_data_tag: string}
 * @phpstan-type CurrentStructureFeatureFields array{current_structure_feature: string}
 * @phpstan-type ServerBoundDiagnosticsFields array{avg_fps: float, avg_server_sim_tick_time_ms: float, avg_client_sim_tick_time_ms: float, avg_begin_frame_time_ms: float, avg_input_time_ms: float, avg_render_time_ms: float, avg_end_frame_time_ms: float, avg_remainder_time_percent: float, avg_unaccounted_time_percent: float, memory_category_values: list<MemoryCategoryCounter>, entity_diagnostics: list<EntityDiagnosticTimingInfo>, system_diagnostics: list<SystemDiagnosticTimingInfo>, system_categories: list<SystemCategory>, whisker_scopes: list<WhiskerScopeDataSummary>}
 * @phpstan-type CameraAimAssistFields array{preset_id: string, view_angle: Vec2f, distance: float, target_mode: CameraAimAssistPacketTargetMode, action: CameraAimAssistAction, show_debug_render: bool}
 * @phpstan-type ContainerRegistryCleanupFields array{removed_containers: list<FullContainerName>}
 * @phpstan-type MovementEffectFields array{target_runtime_id: int, effect_id: MovementEffectType, effect_duration: int, tick: int}
 * @phpstan-type CameraAimAssistPresetsFields array{camera_aim_assist_presets: list<CameraAimAssistCategory>, camera_aim_assist_categories: list<CameraAimAssistPresetDefinition>, operation: CameraAimAssistPresetOperation}
 * @phpstan-type ClientCameraAimAssistFields array{camera_preset_id: string, action: ClientCameraAimAssistAction, allow_aim_assist: bool}
 * @phpstan-type ClientMovementPredictionSyncFields array{actor_data_flag: string, actor_bounding_box: list<float>, movement_attributes: list<float>, actor_unique_id: int, actor_flying_state: bool}
 * @phpstan-type UpdateClientOptionsFields array{graphics_mode_change: GraphicsMode|null, filter_profanity_change: bool|null}
 * @phpstan-type PlayerVideoCaptureFields array{action: PlayerVideoCaptureAction}
 * @phpstan-type PlayerUpdateEntityOverridesFields array{target_id: int, property_index: int, update: PlayerUpdateEntityOverridesUpdate}
 * @phpstan-type PlayerLocationFields array{target_actor_id: int, location: PlayerLocationLocation}
 * @phpstan-type ClientBoundControlSchemeSetFields array{control_scheme: ControlSchemeScheme}
 * @phpstan-type PrimitiveShapesFields array{array_of_primitive_shapes_can_be_a_mix_of_new_updated_or_removed: list<PrimitiveShape>}
 * @phpstan-type ServerBoundPackSettingChangeFields array{pack_id: string, pack_setting_name: string, pack_setting_value: ServerBoundPackSettingChangePackSettingValue}
 * @phpstan-type ClientBoundDataStoreFields array{updates: list<ClientBoundDataStoreUpdatesItem>}
 * @phpstan-type GraphicsOverrideParameterFields array{parameter_keyframe_values: list<PacketParameterKeyframeValuesEntry>, float_value: float|null, vec3_value: Vec3f|null, biome_identifier: string, player_identifier: string|null, identifier_for_parameter: GraphicsOverrideParameterType, reset_parameter: bool}
 * @phpstan-type ServerBoundDataStoreFields array{update: DduiDataStoreUpdate}
 * @phpstan-type ClientBoundDataDrivenUiShowScreenFields array{screen_id: string, form_id: int, data_instance_id: int|null}
 * @phpstan-type ClientBoundDataDrivenUiCloseScreenFields array{form_id: int|null}
 * @phpstan-type ClientBoundDataDrivenUiReloadFields array{}
 * @phpstan-type ClientBoundTextureShiftFields array{action_id: ClientboundTextureShiftAction, collection_name: string, from_step: string, to_step: string, all_steps: list<string>, current_length_in_ticks: int, total_length_in_ticks: int, enabled: bool}
 * @phpstan-type VoxelShapesFields array{shapes: list<VoxelShape>, name_map: list<PacketNameMapEntry>, custom_shape_count: int}
 * @phpstan-type CameraSplineFields array{camera_data_splines: list<CameraSplineDefinition>}
 * @phpstan-type CameraAimAssistActorPriorityFields array{camera_aim_assist_actor_priority_list: list<CameraAimAssistActorPriorityPriorityData>}
 * @phpstan-type ResourcePacksReadyForValidationFields array{}
 * @phpstan-type LocatorBarFields array{waypoints: list<LocatorBarWaypointPayload>}
 * @phpstan-type PartyChangedFields array{party_info: PlayerPartyInfo|null}
 * @phpstan-type ServerBoundDataDrivenScreenClosedFields array{form_id: int, close_reason: string}
 * @phpstan-type SyncWorldClocksFields array{data: SyncWorldClocksData}
 * @phpstan-type ClientBoundAttributeLayerSyncFields array{data: ClientBoundAttributeLayerSyncData}
 * @phpstan-type ServerStoreInfoFields array{client_store_entry_point_configuration: StoreEntryPointInfo|null}
 * @phpstan-type ServerPresenceInfoFields array{presence_configuration: string|null|null}
 * @phpstan-type ClientboundUpdateSoundDataFields array{server_sound_handle: int, stop: SoundDataEvent, set_volume: SoundDataEvent, set_pitch: SoundDataEvent, fade: SoundDataEvent, seek_to: SoundDataEvent, pause: SoundDataEvent, resume: SoundDataEvent}
 * @phpstan-type SendPartyDestinationCookieFields array{cookie: string, intent: string, destination_name: string}
 * @phpstan-type PartyDestinationCookieResponseFields array{cookie: string, accepted: bool}
 * @phpstan-type SetPlayerFurnaceOptionsFields array{furnace_type: SetPlayerFurnaceOptionsFurnaceType, furnace_options: FurnaceOptions}
 * @phpstan-type RecordStartedFields array{block_position: BlockPosition, server_sound_handle: int}
 */
final class Packets{
	public const PROTOCOL = 2193;
	public const SCHEMA_HASH = "b28038026ff9cca49e5e68ea44b041e788bbe63acf02f08cd87ee930aba1b23c";

	/**
	 * Handler methods for packets clients may send.
	 */
	public const HANDLERS = [
		PacketId::LOGIN => "handleLogin",
		PacketId::CLIENT_TO_SERVER_HANDSHAKE => "handleClientToServerHandshake",
		PacketId::DISCONNECT => "handleDisconnect",
		PacketId::RESOURCE_PACK_CLIENT_RESPONSE => "handleResourcePackClientResponse",
		PacketId::TEXT => "handleText",
		PacketId::MOVE_ACTOR_ABSOLUTE => "handleMoveActorAbsolute",
		PacketId::MOVE_PLAYER => "handleMovePlayer",
		PacketId::ACTOR_EVENT => "handleActorEvent",
		PacketId::INVENTORY_TRANSACTION => "handleInventoryTransaction",
		PacketId::MOB_EQUIPMENT => "handleMobEquipment",
		PacketId::MOB_ARMOUR_EQUIPMENT => "handleMobArmourEquipment",
		PacketId::INTERACT => "handleInteract",
		PacketId::BLOCK_PICK_REQUEST => "handleBlockPickRequest",
		PacketId::ACTOR_PICK_REQUEST => "handleActorPickRequest",
		PacketId::PLAYER_ACTION => "handlePlayerAction",
		PacketId::SET_ACTOR_DATA => "handleSetActorData",
		PacketId::SET_ACTOR_MOTION => "handleSetActorMotion",
		PacketId::SET_ACTOR_LINK => "handleSetActorLink",
		PacketId::ANIMATE => "handleAnimate",
		PacketId::RESPAWN => "handleRespawn",
		PacketId::CONTAINER_CLOSE => "handleContainerClose",
		PacketId::PLAYER_HOT_BAR => "handlePlayerHotBar",
		PacketId::BLOCK_ACTOR_DATA => "handleBlockActorData",
		PacketId::SET_DIFFICULTY => "handleSetDifficulty",
		PacketId::SET_PLAYER_GAME_TYPE => "handleSetPlayerGameType",
		PacketId::SIMPLE_EVENT => "handleSimpleEvent",
		PacketId::SPAWN_EXPERIENCE_ORB => "handleSpawnExperienceOrb",
		PacketId::MAP_INFO_REQUEST => "handleMapInfoRequest",
		PacketId::REQUEST_CHUNK_RADIUS => "handleRequestChunkRadius",
		PacketId::BOSS_EVENT => "handleBossEvent",
		PacketId::SHOW_CREDITS => "handleShowCredits",
		PacketId::COMMAND_REQUEST => "handleCommandRequest",
		PacketId::COMMAND_BLOCK_UPDATE => "handleCommandBlockUpdate",
		PacketId::RESOURCE_PACK_CHUNK_REQUEST => "handleResourcePackChunkRequest",
		PacketId::STRUCTURE_BLOCK_UPDATE => "handleStructureBlockUpdate",
		PacketId::PURCHASE_RECEIPT => "handlePurchaseReceipt",
		PacketId::PLAYER_SKIN => "handlePlayerSkin",
		PacketId::SUB_CLIENT_LOGIN => "handleSubClientLogin",
		PacketId::BOOK_EDIT => "handleBookEdit",
		PacketId::NPC_REQUEST => "handleNpcRequest",
		PacketId::MODAL_FORM_RESPONSE => "handleModalFormResponse",
		PacketId::SERVER_SETTINGS_REQUEST => "handleServerSettingsRequest",
		PacketId::SET_DEFAULT_GAME_TYPE => "handleSetDefaultGameType",
		PacketId::LAB_TABLE => "handleLabTable",
		PacketId::SET_LOCAL_PLAYER_AS_INITIALISED => "handleSetLocalPlayerAsInitialised",
		PacketId::NETWORK_STACK_LATENCY => "handleNetworkStackLatency",
		PacketId::LEVEL_SOUND_EVENT => "handleLevelSoundEvent",
		PacketId::LECTERN_UPDATE => "handleLecternUpdate",
		PacketId::CLIENT_CACHE_STATUS => "handleClientCacheStatus",
		PacketId::MAP_CREATE_LOCKED_COPY => "handleMapCreateLockedCopy",
		PacketId::STRUCTURE_TEMPLATE_DATA_REQUEST => "handleStructureTemplateDataRequest",
		PacketId::CLIENT_CACHE_BLOB_STATUS => "handleClientCacheBlobStatus",
		PacketId::EMOTE => "handleEmote",
		PacketId::MULTI_PLAYER_SETTINGS => "handleMultiPlayerSettings",
		PacketId::SETTINGS_COMMAND => "handleSettingsCommand",
		PacketId::ANVIL_DAMAGE => "handleAnvilDamage",
		PacketId::PLAYER_AUTH_INPUT => "handlePlayerAuthInput",
		PacketId::ITEM_STACK_REQUEST => "handleItemStackRequest",
		PacketId::EMOTE_LIST => "handleEmoteList",
		PacketId::POSITION_TRACKING_DB_CLIENT_REQUEST => "handlePositionTrackingDbClientRequest",
		PacketId::DEBUG_INFO => "handleDebugInfo",
		PacketId::PACKET_VIOLATION_WARNING => "handlePacketViolationWarning",
		PacketId::CREATE_PHOTO => "handleCreatePhoto",
		PacketId::SUB_CHUNK_REQUEST => "handleSubChunkRequest",
		PacketId::SCRIPT_MESSAGE => "handleScriptMessage",
		PacketId::CODE_BUILDER_SOURCE => "handleCodeBuilderSource",
		PacketId::CHANGE_MOB_PROPERTY => "handleChangeMobProperty",
		PacketId::REQUEST_ABILITY => "handleRequestAbility",
		PacketId::REQUEST_PERMISSIONS => "handleRequestPermissions",
		PacketId::EDITOR_NETWORK => "handleEditorNetwork",
		PacketId::REQUEST_NETWORK_SETTINGS => "handleRequestNetworkSettings",
		PacketId::GAME_TEST_REQUEST => "handleGameTestRequest",
		PacketId::PLAYER_TOGGLE_CRAFTER_SLOT_REQUEST => "handlePlayerToggleCrafterSlotRequest",
		PacketId::SET_PLAYER_INVENTORY_OPTIONS => "handleSetPlayerInventoryOptions",
		PacketId::SERVER_BOUND_LOADING_SCREEN => "handleServerBoundLoadingScreen",
		PacketId::SERVER_BOUND_DIAGNOSTICS => "handleServerBoundDiagnostics",
		PacketId::CLIENT_CAMERA_AIM_ASSIST => "handleClientCameraAimAssist",
		PacketId::CLIENT_MOVEMENT_PREDICTION_SYNC => "handleClientMovementPredictionSync",
		PacketId::UPDATE_CLIENT_OPTIONS => "handleUpdateClientOptions",
		PacketId::SERVER_BOUND_PACK_SETTING_CHANGE => "handleServerBoundPackSettingChange",
		PacketId::SERVER_BOUND_DATA_STORE => "handleServerBoundDataStore",
		PacketId::RESOURCE_PACKS_READY_FOR_VALIDATION => "handleResourcePacksReadyForValidation",
		PacketId::PARTY_CHANGED => "handlePartyChanged",
		PacketId::SERVER_BOUND_DATA_DRIVEN_SCREEN_CLOSED => "handleServerBoundDataDrivenScreenClosed",
		PacketId::PARTY_DESTINATION_COOKIE_RESPONSE => "handlePartyDestinationCookieResponse",
		PacketId::SET_PLAYER_FURNACE_OPTIONS => "handleSetPlayerFurnaceOptions",
	];

	/**
	 * Packets servers may send.
	 */
	public const CLIENTBOUND = [
		PacketId::PLAY_STATUS => true,
		PacketId::SERVER_TO_CLIENT_HANDSHAKE => true,
		PacketId::DISCONNECT => true,
		PacketId::RESOURCE_PACKS_INFO => true,
		PacketId::RESOURCE_PACK_STACK => true,
		PacketId::TEXT => true,
		PacketId::SET_TIME => true,
		PacketId::START_GAME => true,
		PacketId::ADD_PLAYER => true,
		PacketId::ADD_ACTOR => true,
		PacketId::REMOVE_ACTOR => true,
		PacketId::ADD_ITEM_ACTOR => true,
		PacketId::SERVER_PLAYER_POST_MOVE_POSITION => true,
		PacketId::TAKE_ITEM_ACTOR => true,
		PacketId::MOVE_ACTOR_ABSOLUTE => true,
		PacketId::MOVE_PLAYER => true,
		PacketId::UPDATE_BLOCK => true,
		PacketId::ADD_PAINTING => true,
		PacketId::LEVEL_EVENT => true,
		PacketId::BLOCK_EVENT => true,
		PacketId::ACTOR_EVENT => true,
		PacketId::MOB_EFFECT => true,
		PacketId::UPDATE_ATTRIBUTES => true,
		PacketId::INVENTORY_TRANSACTION => true,
		PacketId::MOB_EQUIPMENT => true,
		PacketId::MOB_ARMOUR_EQUIPMENT => true,
		PacketId::PLAYER_ACTION => true,
		PacketId::HURT_ARMOUR => true,
		PacketId::SET_ACTOR_DATA => true,
		PacketId::SET_ACTOR_MOTION => true,
		PacketId::SET_ACTOR_LINK => true,
		PacketId::SET_HEALTH => true,
		PacketId::SET_SPAWN_POSITION => true,
		PacketId::ANIMATE => true,
		PacketId::RESPAWN => true,
		PacketId::CONTAINER_OPEN => true,
		PacketId::CONTAINER_CLOSE => true,
		PacketId::PLAYER_HOT_BAR => true,
		PacketId::INVENTORY_CONTENT => true,
		PacketId::INVENTORY_SLOT => true,
		PacketId::CONTAINER_SET_DATA => true,
		PacketId::CRAFTING_DATA => true,
		PacketId::GUI_DATA_PICK_ITEM => true,
		PacketId::BLOCK_ACTOR_DATA => true,
		PacketId::LEVEL_CHUNK => true,
		PacketId::SET_COMMANDS_ENABLED => true,
		PacketId::SET_DIFFICULTY => true,
		PacketId::CHANGE_DIMENSION => true,
		PacketId::SET_PLAYER_GAME_TYPE => true,
		PacketId::PLAYER_LIST => true,
		PacketId::SIMPLE_EVENT => true,
		PacketId::EVENT => true,
		PacketId::CLIENT_BOUND_MAP_ITEM_DATA => true,
		PacketId::CHUNK_RADIUS_UPDATED => true,
		PacketId::GAME_RULES_CHANGED => true,
		PacketId::CAMERA => true,
		PacketId::BOSS_EVENT => true,
		PacketId::SHOW_CREDITS => true,
		PacketId::AVAILABLE_COMMANDS => true,
		PacketId::COMMAND_OUTPUT => true,
		PacketId::UPDATE_TRADE => true,
		PacketId::UPDATE_EQUIP => true,
		PacketId::RESOURCE_PACK_DATA_INFO => true,
		PacketId::RESOURCE_PACK_CHUNK_DATA => true,
		PacketId::TRANSFER => true,
		PacketId::PLAY_SOUND => true,
		PacketId::STOP_SOUND => true,
		PacketId::SET_TITLE => true,
		PacketId::ADD_BEHAVIOUR_TREE => true,
		PacketId::SHOW_STORE_OFFER => true,
		PacketId::PLAYER_SKIN => true,
		PacketId::AUTOMATION_CLIENT_CONNECT => true,
		PacketId::SET_LAST_HURT_BY => true,
		PacketId::PHOTO_TRANSFER => true,
		PacketId::MODAL_FORM_REQUEST => true,
		PacketId::SERVER_SETTINGS_RESPONSE => true,
		PacketId::SHOW_PROFILE => true,
		PacketId::SET_DEFAULT_GAME_TYPE => true,
		PacketId::REMOVE_OBJECTIVE => true,
		PacketId::SET_DISPLAY_OBJECTIVE => true,
		PacketId::SET_SCORE => true,
		PacketId::LAB_TABLE => true,
		PacketId::UPDATE_BLOCK_SYNCED => true,
		PacketId::MOVE_ACTOR_DELTA => true,
		PacketId::SET_SCOREBOARD_IDENTITY => true,
		PacketId::UPDATE_SOFT_ENUM => true,
		PacketId::NETWORK_STACK_LATENCY => true,
		PacketId::SPAWN_PARTICLE_EFFECT => true,
		PacketId::AVAILABLE_ACTOR_IDENTIFIERS => true,
		PacketId::NETWORK_CHUNK_PUBLISHER_UPDATE => true,
		PacketId::BIOME_DEFINITION_LIST => true,
		PacketId::LEVEL_SOUND_EVENT => true,
		PacketId::LEVEL_EVENT_GENERIC => true,
		PacketId::ON_SCREEN_TEXTURE_ANIMATION => true,
		PacketId::STRUCTURE_TEMPLATE_DATA_RESPONSE => true,
		PacketId::CLIENT_CACHE_MISS_RESPONSE => true,
		PacketId::EDUCATION_SETTINGS => true,
		PacketId::EMOTE => true,
		PacketId::MULTI_PLAYER_SETTINGS => true,
		PacketId::COMPLETED_USING_ITEM => true,
		PacketId::NETWORK_SETTINGS => true,
		PacketId::CREATIVE_CONTENT => true,
		PacketId::PLAYER_ENCHANT_OPTIONS => true,
		PacketId::ITEM_STACK_RESPONSE => true,
		PacketId::PLAYER_ARMOUR_DAMAGE => true,
		PacketId::CODE_BUILDER => true,
		PacketId::UPDATE_PLAYER_GAME_TYPE => true,
		PacketId::EMOTE_LIST => true,
		PacketId::POSITION_TRACKING_DB_SERVER_BROADCAST => true,
		PacketId::DEBUG_INFO => true,
		PacketId::MOTION_PREDICTION_HINTS => true,
		PacketId::ANIMATE_ENTITY => true,
		PacketId::CAMERA_SHAKE => true,
		PacketId::PLAYER_FOG => true,
		PacketId::CORRECT_PLAYER_MOVE_PREDICTION => true,
		PacketId::ITEM_REGISTRY => true,
		PacketId::CLIENT_BOUND_DEBUG_RENDERER => true,
		PacketId::SYNC_ACTOR_PROPERTY => true,
		PacketId::ADD_VOLUME_ENTITY => true,
		PacketId::REMOVE_VOLUME_ENTITY => true,
		PacketId::SIMULATION_TYPE => true,
		PacketId::NPC_DIALOGUE => true,
		PacketId::EDUCATION_RESOURCE_URI => true,
		PacketId::UPDATE_SUB_CHUNK_BLOCKS => true,
		PacketId::SUB_CHUNK => true,
		PacketId::CLIENT_START_ITEM_COOLDOWN => true,
		PacketId::SCRIPT_MESSAGE => true,
		PacketId::TICKING_AREAS_LOAD_STATUS => true,
		PacketId::DIMENSION_DATA => true,
		PacketId::AGENT_ACTION => true,
		PacketId::LESSON_PROGRESS => true,
		PacketId::TOAST_REQUEST => true,
		PacketId::UPDATE_ABILITIES => true,
		PacketId::UPDATE_ADVENTURE_SETTINGS => true,
		PacketId::DEATH_INFO => true,
		PacketId::EDITOR_NETWORK => true,
		PacketId::FEATURE_REGISTRY => true,
		PacketId::SERVER_STATS => true,
		PacketId::GAME_TEST_RESULTS => true,
		PacketId::UPDATE_CLIENT_INPUT_LOCKS => true,
		PacketId::CAMERA_PRESETS => true,
		PacketId::UNLOCKED_RECIPES => true,
		PacketId::CAMERA_INSTRUCTION => true,
		PacketId::TRIM_DATA => true,
		PacketId::OPEN_SIGN => true,
		PacketId::AGENT_ANIMATION => true,
		PacketId::REFRESH_ENTITLEMENTS => true,
		PacketId::SET_PLAYER_INVENTORY_OPTIONS => true,
		PacketId::SET_HUD => true,
		PacketId::AWARD_ACHIEVEMENT => true,
		PacketId::CLIENT_BOUND_CLOSE_FORM => true,
		PacketId::JIGSAW_STRUCTURE_DATA => true,
		PacketId::CURRENT_STRUCTURE_FEATURE => true,
		PacketId::CAMERA_AIM_ASSIST => true,
		PacketId::CONTAINER_REGISTRY_CLEANUP => true,
		PacketId::MOVEMENT_EFFECT => true,
		PacketId::CAMERA_AIM_ASSIST_PRESETS => true,
		PacketId::PLAYER_VIDEO_CAPTURE => true,
		PacketId::PLAYER_UPDATE_ENTITY_OVERRIDES => true,
		PacketId::PLAYER_LOCATION => true,
		PacketId::CLIENT_BOUND_CONTROL_SCHEME_SET => true,
		PacketId::PRIMITIVE_SHAPES => true,
		PacketId::CLIENT_BOUND_DATA_STORE => true,
		PacketId::GRAPHICS_OVERRIDE_PARAMETER => true,
		PacketId::CLIENT_BOUND_DATA_DRIVEN_UI_SHOW_SCREEN => true,
		PacketId::CLIENT_BOUND_DATA_DRIVEN_UI_CLOSE_SCREEN => true,
		PacketId::CLIENT_BOUND_DATA_DRIVEN_UI_RELOAD => true,
		PacketId::CLIENT_BOUND_TEXTURE_SHIFT => true,
		PacketId::VOXEL_SHAPES => true,
		PacketId::CAMERA_SPLINE => true,
		PacketId::CAMERA_AIM_ASSIST_ACTOR_PRIORITY => true,
		PacketId::LOCATOR_BAR => true,
		PacketId::SYNC_WORLD_CLOCKS => true,
		PacketId::CLIENT_BOUND_ATTRIBUTE_LAYER_SYNC => true,
		PacketId::SERVER_STORE_INFO => true,
		PacketId::SERVER_PRESENCE_INFO => true,
		PacketId::CLIENTBOUND_UPDATE_SOUND_DATA => true,
		PacketId::SEND_PARTY_DESTINATION_COOKIE => true,
		PacketId::RECORD_STARTED => true,
	];

	private function __construct(){
		//NOOP
	}

	/**
	 * @phpstan-param LoginFields $fields
	 */
	public static function login(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::LOGIN, $fields);
	}

	/**
	 * @phpstan-param PlayStatusFields $fields
	 */
	public static function playStatus(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PLAY_STATUS, $fields);
	}

	/**
	 * @phpstan-param ServerToClientHandshakeFields $fields
	 */
	public static function serverToClientHandshake(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SERVER_TO_CLIENT_HANDSHAKE, $fields);
	}

	/**
	 * @phpstan-param ClientToServerHandshakeFields $fields
	 */
	public static function clientToServerHandshake(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CLIENT_TO_SERVER_HANDSHAKE, $fields);
	}

	/**
	 * @phpstan-param DisconnectFields $fields
	 */
	public static function disconnect(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::DISCONNECT, $fields);
	}

	/**
	 * @phpstan-param ResourcePacksInfoFields $fields
	 */
	public static function resourcePacksInfo(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::RESOURCE_PACKS_INFO, $fields);
	}

	/**
	 * @phpstan-param ResourcePackStackFields $fields
	 */
	public static function resourcePackStack(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::RESOURCE_PACK_STACK, $fields);
	}

	/**
	 * @phpstan-param ResourcePackClientResponseFields $fields
	 */
	public static function resourcePackClientResponse(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::RESOURCE_PACK_CLIENT_RESPONSE, $fields);
	}

	/**
	 * @phpstan-param TextFields $fields
	 */
	public static function text(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::TEXT, $fields);
	}

	/**
	 * @phpstan-param SetTimeFields $fields
	 */
	public static function setTime(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SET_TIME, $fields);
	}

	/**
	 * @phpstan-param StartGameFields $fields
	 */
	public static function startGame(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::START_GAME, $fields);
	}

	/**
	 * @phpstan-param AddPlayerFields $fields
	 */
	public static function addPlayer(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::ADD_PLAYER, $fields);
	}

	/**
	 * @phpstan-param AddActorFields $fields
	 */
	public static function addActor(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::ADD_ACTOR, $fields);
	}

	/**
	 * @phpstan-param RemoveActorFields $fields
	 */
	public static function removeActor(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::REMOVE_ACTOR, $fields);
	}

	/**
	 * @phpstan-param AddItemActorFields $fields
	 */
	public static function addItemActor(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::ADD_ITEM_ACTOR, $fields);
	}

	/**
	 * @phpstan-param ServerPlayerPostMovePositionFields $fields
	 */
	public static function serverPlayerPostMovePosition(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SERVER_PLAYER_POST_MOVE_POSITION, $fields);
	}

	/**
	 * @phpstan-param TakeItemActorFields $fields
	 */
	public static function takeItemActor(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::TAKE_ITEM_ACTOR, $fields);
	}

	/**
	 * @phpstan-param MoveActorAbsoluteFields $fields
	 */
	public static function moveActorAbsolute(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::MOVE_ACTOR_ABSOLUTE, $fields);
	}

	/**
	 * @phpstan-param MovePlayerFields $fields
	 */
	public static function movePlayer(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::MOVE_PLAYER, $fields);
	}

	/**
	 * @phpstan-param UpdateBlockFields $fields
	 */
	public static function updateBlock(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::UPDATE_BLOCK, $fields);
	}

	/**
	 * @phpstan-param AddPaintingFields $fields
	 */
	public static function addPainting(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::ADD_PAINTING, $fields);
	}

	/**
	 * @phpstan-param LevelEventFields $fields
	 */
	public static function levelEvent(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::LEVEL_EVENT, $fields);
	}

	/**
	 * @phpstan-param BlockEventFields $fields
	 */
	public static function blockEvent(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::BLOCK_EVENT, $fields);
	}

	/**
	 * @phpstan-param ActorEventFields $fields
	 */
	public static function actorEvent(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::ACTOR_EVENT, $fields);
	}

	/**
	 * @phpstan-param MobEffectFields $fields
	 */
	public static function mobEffect(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::MOB_EFFECT, $fields);
	}

	/**
	 * @phpstan-param UpdateAttributesFields $fields
	 */
	public static function updateAttributes(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::UPDATE_ATTRIBUTES, $fields);
	}

	/**
	 * @phpstan-param InventoryTransactionFields $fields
	 */
	public static function inventoryTransaction(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::INVENTORY_TRANSACTION, $fields);
	}

	/**
	 * @phpstan-param MobEquipmentFields $fields
	 */
	public static function mobEquipment(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::MOB_EQUIPMENT, $fields);
	}

	/**
	 * @phpstan-param MobArmourEquipmentFields $fields
	 */
	public static function mobArmourEquipment(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::MOB_ARMOUR_EQUIPMENT, $fields);
	}

	/**
	 * @phpstan-param InteractFields $fields
	 */
	public static function interact(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::INTERACT, $fields);
	}

	/**
	 * @phpstan-param BlockPickRequestFields $fields
	 */
	public static function blockPickRequest(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::BLOCK_PICK_REQUEST, $fields);
	}

	/**
	 * @phpstan-param ActorPickRequestFields $fields
	 */
	public static function actorPickRequest(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::ACTOR_PICK_REQUEST, $fields);
	}

	/**
	 * @phpstan-param PlayerActionFields $fields
	 */
	public static function playerAction(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PLAYER_ACTION, $fields);
	}

	/**
	 * @phpstan-param HurtArmourFields $fields
	 */
	public static function hurtArmour(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::HURT_ARMOUR, $fields);
	}

	/**
	 * @phpstan-param SetActorDataFields $fields
	 */
	public static function setActorData(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SET_ACTOR_DATA, $fields);
	}

	/**
	 * @phpstan-param SetActorMotionFields $fields
	 */
	public static function setActorMotion(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SET_ACTOR_MOTION, $fields);
	}

	/**
	 * @phpstan-param SetActorLinkFields $fields
	 */
	public static function setActorLink(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SET_ACTOR_LINK, $fields);
	}

	/**
	 * @phpstan-param SetHealthFields $fields
	 */
	public static function setHealth(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SET_HEALTH, $fields);
	}

	/**
	 * @phpstan-param SetSpawnPositionFields $fields
	 */
	public static function setSpawnPosition(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SET_SPAWN_POSITION, $fields);
	}

	/**
	 * @phpstan-param AnimateFields $fields
	 */
	public static function animate(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::ANIMATE, $fields);
	}

	/**
	 * @phpstan-param RespawnFields $fields
	 */
	public static function respawn(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::RESPAWN, $fields);
	}

	/**
	 * @phpstan-param ContainerOpenFields $fields
	 */
	public static function containerOpen(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CONTAINER_OPEN, $fields);
	}

	/**
	 * @phpstan-param ContainerCloseFields $fields
	 */
	public static function containerClose(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CONTAINER_CLOSE, $fields);
	}

	/**
	 * @phpstan-param PlayerHotBarFields $fields
	 */
	public static function playerHotBar(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PLAYER_HOT_BAR, $fields);
	}

	/**
	 * @phpstan-param InventoryContentFields $fields
	 */
	public static function inventoryContent(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::INVENTORY_CONTENT, $fields);
	}

	/**
	 * @phpstan-param InventorySlotFields $fields
	 */
	public static function inventorySlot(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::INVENTORY_SLOT, $fields);
	}

	/**
	 * @phpstan-param ContainerSetDataFields $fields
	 */
	public static function containerSetData(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CONTAINER_SET_DATA, $fields);
	}

	/**
	 * @phpstan-param CraftingDataFields $fields
	 */
	public static function craftingData(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CRAFTING_DATA, $fields);
	}

	/**
	 * @phpstan-param GuiDataPickItemFields $fields
	 */
	public static function guiDataPickItem(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::GUI_DATA_PICK_ITEM, $fields);
	}

	/**
	 * @phpstan-param BlockActorDataFields $fields
	 */
	public static function blockActorData(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::BLOCK_ACTOR_DATA, $fields);
	}

	/**
	 * @phpstan-param LevelChunkFields $fields
	 */
	public static function levelChunk(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::LEVEL_CHUNK, $fields);
	}

	/**
	 * @phpstan-param SetCommandsEnabledFields $fields
	 */
	public static function setCommandsEnabled(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SET_COMMANDS_ENABLED, $fields);
	}

	/**
	 * @phpstan-param SetDifficultyFields $fields
	 */
	public static function setDifficulty(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SET_DIFFICULTY, $fields);
	}

	/**
	 * @phpstan-param ChangeDimensionFields $fields
	 */
	public static function changeDimension(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CHANGE_DIMENSION, $fields);
	}

	/**
	 * @phpstan-param SetPlayerGameTypeFields $fields
	 */
	public static function setPlayerGameType(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SET_PLAYER_GAME_TYPE, $fields);
	}

	/**
	 * @phpstan-param PlayerListFields $fields
	 */
	public static function playerList(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PLAYER_LIST, $fields);
	}

	/**
	 * @phpstan-param SimpleEventFields $fields
	 */
	public static function simpleEvent(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SIMPLE_EVENT, $fields);
	}

	/**
	 * @phpstan-param EventFields $fields
	 */
	public static function event(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::EVENT, $fields);
	}

	/**
	 * @phpstan-param SpawnExperienceOrbFields $fields
	 */
	public static function spawnExperienceOrb(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SPAWN_EXPERIENCE_ORB, $fields);
	}

	/**
	 * @phpstan-param ClientBoundMapItemDataFields $fields
	 */
	public static function clientBoundMapItemData(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CLIENT_BOUND_MAP_ITEM_DATA, $fields);
	}

	/**
	 * @phpstan-param MapInfoRequestFields $fields
	 */
	public static function mapInfoRequest(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::MAP_INFO_REQUEST, $fields);
	}

	/**
	 * @phpstan-param RequestChunkRadiusFields $fields
	 */
	public static function requestChunkRadius(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::REQUEST_CHUNK_RADIUS, $fields);
	}

	/**
	 * @phpstan-param ChunkRadiusUpdatedFields $fields
	 */
	public static function chunkRadiusUpdated(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CHUNK_RADIUS_UPDATED, $fields);
	}

	/**
	 * @phpstan-param GameRulesChangedFields $fields
	 */
	public static function gameRulesChanged(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::GAME_RULES_CHANGED, $fields);
	}

	/**
	 * @phpstan-param CameraFields $fields
	 */
	public static function camera(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CAMERA, $fields);
	}

	/**
	 * @phpstan-param BossEventFields $fields
	 */
	public static function bossEvent(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::BOSS_EVENT, $fields);
	}

	/**
	 * @phpstan-param ShowCreditsFields $fields
	 */
	public static function showCredits(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SHOW_CREDITS, $fields);
	}

	/**
	 * @phpstan-param AvailableCommandsFields $fields
	 */
	public static function availableCommands(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::AVAILABLE_COMMANDS, $fields);
	}

	/**
	 * @phpstan-param CommandRequestFields $fields
	 */
	public static function commandRequest(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::COMMAND_REQUEST, $fields);
	}

	/**
	 * @phpstan-param CommandBlockUpdateFields $fields
	 */
	public static function commandBlockUpdate(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::COMMAND_BLOCK_UPDATE, $fields);
	}

	/**
	 * @phpstan-param CommandOutputFields $fields
	 */
	public static function commandOutput(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::COMMAND_OUTPUT, $fields);
	}

	/**
	 * @phpstan-param UpdateTradeFields $fields
	 */
	public static function updateTrade(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::UPDATE_TRADE, $fields);
	}

	/**
	 * @phpstan-param UpdateEquipFields $fields
	 */
	public static function updateEquip(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::UPDATE_EQUIP, $fields);
	}

	/**
	 * @phpstan-param ResourcePackDataInfoFields $fields
	 */
	public static function resourcePackDataInfo(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::RESOURCE_PACK_DATA_INFO, $fields);
	}

	/**
	 * @phpstan-param ResourcePackChunkDataFields $fields
	 */
	public static function resourcePackChunkData(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::RESOURCE_PACK_CHUNK_DATA, $fields);
	}

	/**
	 * @phpstan-param ResourcePackChunkRequestFields $fields
	 */
	public static function resourcePackChunkRequest(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::RESOURCE_PACK_CHUNK_REQUEST, $fields);
	}

	/**
	 * @phpstan-param TransferFields $fields
	 */
	public static function transfer(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::TRANSFER, $fields);
	}

	/**
	 * @phpstan-param PlaySoundFields $fields
	 */
	public static function playSound(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PLAY_SOUND, $fields);
	}

	/**
	 * @phpstan-param StopSoundFields $fields
	 */
	public static function stopSound(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::STOP_SOUND, $fields);
	}

	/**
	 * @phpstan-param SetTitleFields $fields
	 */
	public static function setTitle(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SET_TITLE, $fields);
	}

	/**
	 * @phpstan-param AddBehaviourTreeFields $fields
	 */
	public static function addBehaviourTree(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::ADD_BEHAVIOUR_TREE, $fields);
	}

	/**
	 * @phpstan-param StructureBlockUpdateFields $fields
	 */
	public static function structureBlockUpdate(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::STRUCTURE_BLOCK_UPDATE, $fields);
	}

	/**
	 * @phpstan-param ShowStoreOfferFields $fields
	 */
	public static function showStoreOffer(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SHOW_STORE_OFFER, $fields);
	}

	/**
	 * @phpstan-param PurchaseReceiptFields $fields
	 */
	public static function purchaseReceipt(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PURCHASE_RECEIPT, $fields);
	}

	/**
	 * @phpstan-param PlayerSkinFields $fields
	 */
	public static function playerSkin(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PLAYER_SKIN, $fields);
	}

	/**
	 * @phpstan-param SubClientLoginFields $fields
	 */
	public static function subClientLogin(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SUB_CLIENT_LOGIN, $fields);
	}

	/**
	 * @phpstan-param AutomationClientConnectFields $fields
	 */
	public static function automationClientConnect(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::AUTOMATION_CLIENT_CONNECT, $fields);
	}

	/**
	 * @phpstan-param SetLastHurtByFields $fields
	 */
	public static function setLastHurtBy(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SET_LAST_HURT_BY, $fields);
	}

	/**
	 * @phpstan-param BookEditFields $fields
	 */
	public static function bookEdit(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::BOOK_EDIT, $fields);
	}

	/**
	 * @phpstan-param NpcRequestFields $fields
	 */
	public static function npcRequest(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::NPC_REQUEST, $fields);
	}

	/**
	 * @phpstan-param PhotoTransferFields $fields
	 */
	public static function photoTransfer(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PHOTO_TRANSFER, $fields);
	}

	/**
	 * @phpstan-param ModalFormRequestFields $fields
	 */
	public static function modalFormRequest(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::MODAL_FORM_REQUEST, $fields);
	}

	/**
	 * @phpstan-param ModalFormResponseFields $fields
	 */
	public static function modalFormResponse(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::MODAL_FORM_RESPONSE, $fields);
	}

	/**
	 * @phpstan-param ServerSettingsRequestFields $fields
	 */
	public static function serverSettingsRequest(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SERVER_SETTINGS_REQUEST, $fields);
	}

	/**
	 * @phpstan-param ServerSettingsResponseFields $fields
	 */
	public static function serverSettingsResponse(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SERVER_SETTINGS_RESPONSE, $fields);
	}

	/**
	 * @phpstan-param ShowProfileFields $fields
	 */
	public static function showProfile(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SHOW_PROFILE, $fields);
	}

	/**
	 * @phpstan-param SetDefaultGameTypeFields $fields
	 */
	public static function setDefaultGameType(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SET_DEFAULT_GAME_TYPE, $fields);
	}

	/**
	 * @phpstan-param RemoveObjectiveFields $fields
	 */
	public static function removeObjective(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::REMOVE_OBJECTIVE, $fields);
	}

	/**
	 * @phpstan-param SetDisplayObjectiveFields $fields
	 */
	public static function setDisplayObjective(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SET_DISPLAY_OBJECTIVE, $fields);
	}

	/**
	 * @phpstan-param SetScoreFields $fields
	 */
	public static function setScore(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SET_SCORE, $fields);
	}

	/**
	 * @phpstan-param LabTableFields $fields
	 */
	public static function labTable(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::LAB_TABLE, $fields);
	}

	/**
	 * @phpstan-param UpdateBlockSyncedFields $fields
	 */
	public static function updateBlockSynced(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::UPDATE_BLOCK_SYNCED, $fields);
	}

	/**
	 * @phpstan-param MoveActorDeltaFields $fields
	 */
	public static function moveActorDelta(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::MOVE_ACTOR_DELTA, $fields);
	}

	/**
	 * @phpstan-param SetScoreboardIdentityFields $fields
	 */
	public static function setScoreboardIdentity(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SET_SCOREBOARD_IDENTITY, $fields);
	}

	/**
	 * @phpstan-param SetLocalPlayerAsInitialisedFields $fields
	 */
	public static function setLocalPlayerAsInitialised(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SET_LOCAL_PLAYER_AS_INITIALISED, $fields);
	}

	/**
	 * @phpstan-param UpdateSoftEnumFields $fields
	 */
	public static function updateSoftEnum(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::UPDATE_SOFT_ENUM, $fields);
	}

	/**
	 * @phpstan-param NetworkStackLatencyFields $fields
	 */
	public static function networkStackLatency(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::NETWORK_STACK_LATENCY, $fields);
	}

	/**
	 * @phpstan-param SpawnParticleEffectFields $fields
	 */
	public static function spawnParticleEffect(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SPAWN_PARTICLE_EFFECT, $fields);
	}

	/**
	 * @phpstan-param AvailableActorIdentifiersFields $fields
	 */
	public static function availableActorIdentifiers(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::AVAILABLE_ACTOR_IDENTIFIERS, $fields);
	}

	/**
	 * @phpstan-param NetworkChunkPublisherUpdateFields $fields
	 */
	public static function networkChunkPublisherUpdate(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::NETWORK_CHUNK_PUBLISHER_UPDATE, $fields);
	}

	/**
	 * @phpstan-param BiomeDefinitionListFields $fields
	 */
	public static function biomeDefinitionList(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::BIOME_DEFINITION_LIST, $fields);
	}

	/**
	 * @phpstan-param LevelSoundEventFields $fields
	 */
	public static function levelSoundEvent(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::LEVEL_SOUND_EVENT, $fields);
	}

	/**
	 * @phpstan-param LevelEventGenericFields $fields
	 */
	public static function levelEventGeneric(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::LEVEL_EVENT_GENERIC, $fields);
	}

	/**
	 * @phpstan-param LecternUpdateFields $fields
	 */
	public static function lecternUpdate(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::LECTERN_UPDATE, $fields);
	}

	/**
	 * @phpstan-param ClientCacheStatusFields $fields
	 */
	public static function clientCacheStatus(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CLIENT_CACHE_STATUS, $fields);
	}

	/**
	 * @phpstan-param OnScreenTextureAnimationFields $fields
	 */
	public static function onScreenTextureAnimation(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::ON_SCREEN_TEXTURE_ANIMATION, $fields);
	}

	/**
	 * @phpstan-param MapCreateLockedCopyFields $fields
	 */
	public static function mapCreateLockedCopy(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::MAP_CREATE_LOCKED_COPY, $fields);
	}

	/**
	 * @phpstan-param StructureTemplateDataRequestFields $fields
	 */
	public static function structureTemplateDataRequest(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::STRUCTURE_TEMPLATE_DATA_REQUEST, $fields);
	}

	/**
	 * @phpstan-param StructureTemplateDataResponseFields $fields
	 */
	public static function structureTemplateDataResponse(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::STRUCTURE_TEMPLATE_DATA_RESPONSE, $fields);
	}

	/**
	 * @phpstan-param ClientCacheBlobStatusFields $fields
	 */
	public static function clientCacheBlobStatus(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CLIENT_CACHE_BLOB_STATUS, $fields);
	}

	/**
	 * @phpstan-param ClientCacheMissResponseFields $fields
	 */
	public static function clientCacheMissResponse(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CLIENT_CACHE_MISS_RESPONSE, $fields);
	}

	/**
	 * @phpstan-param EducationSettingsFields $fields
	 */
	public static function educationSettings(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::EDUCATION_SETTINGS, $fields);
	}

	/**
	 * @phpstan-param EmoteFields $fields
	 */
	public static function emote(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::EMOTE, $fields);
	}

	/**
	 * @phpstan-param MultiPlayerSettingsFields $fields
	 */
	public static function multiPlayerSettings(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::MULTI_PLAYER_SETTINGS, $fields);
	}

	/**
	 * @phpstan-param SettingsCommandFields $fields
	 */
	public static function settingsCommand(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SETTINGS_COMMAND, $fields);
	}

	/**
	 * @phpstan-param AnvilDamageFields $fields
	 */
	public static function anvilDamage(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::ANVIL_DAMAGE, $fields);
	}

	/**
	 * @phpstan-param CompletedUsingItemFields $fields
	 */
	public static function completedUsingItem(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::COMPLETED_USING_ITEM, $fields);
	}

	/**
	 * @phpstan-param NetworkSettingsFields $fields
	 */
	public static function networkSettings(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::NETWORK_SETTINGS, $fields);
	}

	/**
	 * @phpstan-param PlayerAuthInputFields $fields
	 */
	public static function playerAuthInput(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PLAYER_AUTH_INPUT, $fields);
	}

	/**
	 * @phpstan-param CreativeContentFields $fields
	 */
	public static function creativeContent(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CREATIVE_CONTENT, $fields);
	}

	/**
	 * @phpstan-param PlayerEnchantOptionsFields $fields
	 */
	public static function playerEnchantOptions(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PLAYER_ENCHANT_OPTIONS, $fields);
	}

	/**
	 * @phpstan-param ItemStackRequestFields $fields
	 */
	public static function itemStackRequest(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::ITEM_STACK_REQUEST, $fields);
	}

	/**
	 * @phpstan-param ItemStackResponseFields $fields
	 */
	public static function itemStackResponse(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::ITEM_STACK_RESPONSE, $fields);
	}

	/**
	 * @phpstan-param PlayerArmourDamageFields $fields
	 */
	public static function playerArmourDamage(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PLAYER_ARMOUR_DAMAGE, $fields);
	}

	/**
	 * @phpstan-param CodeBuilderFields $fields
	 */
	public static function codeBuilder(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CODE_BUILDER, $fields);
	}

	/**
	 * @phpstan-param UpdatePlayerGameTypeFields $fields
	 */
	public static function updatePlayerGameType(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::UPDATE_PLAYER_GAME_TYPE, $fields);
	}

	/**
	 * @phpstan-param EmoteListFields $fields
	 */
	public static function emoteList(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::EMOTE_LIST, $fields);
	}

	/**
	 * @phpstan-param PositionTrackingDbServerBroadcastFields $fields
	 */
	public static function positionTrackingDbServerBroadcast(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::POSITION_TRACKING_DB_SERVER_BROADCAST, $fields);
	}

	/**
	 * @phpstan-param PositionTrackingDbClientRequestFields $fields
	 */
	public static function positionTrackingDbClientRequest(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::POSITION_TRACKING_DB_CLIENT_REQUEST, $fields);
	}

	/**
	 * @phpstan-param DebugInfoFields $fields
	 */
	public static function debugInfo(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::DEBUG_INFO, $fields);
	}

	/**
	 * @phpstan-param PacketViolationWarningFields $fields
	 */
	public static function packetViolationWarning(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PACKET_VIOLATION_WARNING, $fields);
	}

	/**
	 * @phpstan-param MotionPredictionHintsFields $fields
	 */
	public static function motionPredictionHints(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::MOTION_PREDICTION_HINTS, $fields);
	}

	/**
	 * @phpstan-param AnimateEntityFields $fields
	 */
	public static function animateEntity(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::ANIMATE_ENTITY, $fields);
	}

	/**
	 * @phpstan-param CameraShakeFields $fields
	 */
	public static function cameraShake(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CAMERA_SHAKE, $fields);
	}

	/**
	 * @phpstan-param PlayerFogFields $fields
	 */
	public static function playerFog(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PLAYER_FOG, $fields);
	}

	/**
	 * @phpstan-param CorrectPlayerMovePredictionFields $fields
	 */
	public static function correctPlayerMovePrediction(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CORRECT_PLAYER_MOVE_PREDICTION, $fields);
	}

	/**
	 * @phpstan-param ItemRegistryFields $fields
	 */
	public static function itemRegistry(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::ITEM_REGISTRY, $fields);
	}

	/**
	 * @phpstan-param ClientBoundDebugRendererFields $fields
	 */
	public static function clientBoundDebugRenderer(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CLIENT_BOUND_DEBUG_RENDERER, $fields);
	}

	/**
	 * @phpstan-param SyncActorPropertyFields $fields
	 */
	public static function syncActorProperty(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SYNC_ACTOR_PROPERTY, $fields);
	}

	/**
	 * @phpstan-param AddVolumeEntityFields $fields
	 */
	public static function addVolumeEntity(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::ADD_VOLUME_ENTITY, $fields);
	}

	/**
	 * @phpstan-param RemoveVolumeEntityFields $fields
	 */
	public static function removeVolumeEntity(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::REMOVE_VOLUME_ENTITY, $fields);
	}

	/**
	 * @phpstan-param SimulationTypeFields $fields
	 */
	public static function simulationType(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SIMULATION_TYPE, $fields);
	}

	/**
	 * @phpstan-param NpcDialogueFields $fields
	 */
	public static function npcDialogue(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::NPC_DIALOGUE, $fields);
	}

	/**
	 * @phpstan-param EducationResourceUriFields $fields
	 */
	public static function educationResourceUri(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::EDUCATION_RESOURCE_URI, $fields);
	}

	/**
	 * @phpstan-param CreatePhotoFields $fields
	 */
	public static function createPhoto(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CREATE_PHOTO, $fields);
	}

	/**
	 * @phpstan-param UpdateSubChunkBlocksFields $fields
	 */
	public static function updateSubChunkBlocks(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::UPDATE_SUB_CHUNK_BLOCKS, $fields);
	}

	/**
	 * @phpstan-param SubChunkFields $fields
	 */
	public static function subChunk(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SUB_CHUNK, $fields);
	}

	/**
	 * @phpstan-param SubChunkRequestFields $fields
	 */
	public static function subChunkRequest(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SUB_CHUNK_REQUEST, $fields);
	}

	/**
	 * @phpstan-param ClientStartItemCooldownFields $fields
	 */
	public static function clientStartItemCooldown(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CLIENT_START_ITEM_COOLDOWN, $fields);
	}

	/**
	 * @phpstan-param ScriptMessageFields $fields
	 */
	public static function scriptMessage(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SCRIPT_MESSAGE, $fields);
	}

	/**
	 * @phpstan-param CodeBuilderSourceFields $fields
	 */
	public static function codeBuilderSource(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CODE_BUILDER_SOURCE, $fields);
	}

	/**
	 * @phpstan-param TickingAreasLoadStatusFields $fields
	 */
	public static function tickingAreasLoadStatus(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::TICKING_AREAS_LOAD_STATUS, $fields);
	}

	/**
	 * @phpstan-param DimensionDataFields $fields
	 */
	public static function dimensionData(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::DIMENSION_DATA, $fields);
	}

	/**
	 * @phpstan-param AgentActionFields $fields
	 */
	public static function agentAction(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::AGENT_ACTION, $fields);
	}

	/**
	 * @phpstan-param ChangeMobPropertyFields $fields
	 */
	public static function changeMobProperty(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CHANGE_MOB_PROPERTY, $fields);
	}

	/**
	 * @phpstan-param LessonProgressFields $fields
	 */
	public static function lessonProgress(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::LESSON_PROGRESS, $fields);
	}

	/**
	 * @phpstan-param RequestAbilityFields $fields
	 */
	public static function requestAbility(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::REQUEST_ABILITY, $fields);
	}

	/**
	 * @phpstan-param RequestPermissionsFields $fields
	 */
	public static function requestPermissions(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::REQUEST_PERMISSIONS, $fields);
	}

	/**
	 * @phpstan-param ToastRequestFields $fields
	 */
	public static function toastRequest(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::TOAST_REQUEST, $fields);
	}

	/**
	 * @phpstan-param UpdateAbilitiesFields $fields
	 */
	public static function updateAbilities(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::UPDATE_ABILITIES, $fields);
	}

	/**
	 * @phpstan-param UpdateAdventureSettingsFields $fields
	 */
	public static function updateAdventureSettings(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::UPDATE_ADVENTURE_SETTINGS, $fields);
	}

	/**
	 * @phpstan-param DeathInfoFields $fields
	 */
	public static function deathInfo(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::DEATH_INFO, $fields);
	}

	/**
	 * @phpstan-param EditorNetworkFields $fields
	 */
	public static function editorNetwork(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::EDITOR_NETWORK, $fields);
	}

	/**
	 * @phpstan-param FeatureRegistryFields $fields
	 */
	public static function featureRegistry(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::FEATURE_REGISTRY, $fields);
	}

	/**
	 * @phpstan-param ServerStatsFields $fields
	 */
	public static function serverStats(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SERVER_STATS, $fields);
	}

	/**
	 * @phpstan-param RequestNetworkSettingsFields $fields
	 */
	public static function requestNetworkSettings(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::REQUEST_NETWORK_SETTINGS, $fields);
	}

	/**
	 * @phpstan-param GameTestRequestFields $fields
	 */
	public static function gameTestRequest(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::GAME_TEST_REQUEST, $fields);
	}

	/**
	 * @phpstan-param GameTestResultsFields $fields
	 */
	public static function gameTestResults(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::GAME_TEST_RESULTS, $fields);
	}

	/**
	 * @phpstan-param UpdateClientInputLocksFields $fields
	 */
	public static function updateClientInputLocks(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::UPDATE_CLIENT_INPUT_LOCKS, $fields);
	}

	/**
	 * @phpstan-param CameraPresetsFields $fields
	 */
	public static function cameraPresets(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CAMERA_PRESETS, $fields);
	}

	/**
	 * @phpstan-param UnlockedRecipesFields $fields
	 */
	public static function unlockedRecipes(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::UNLOCKED_RECIPES, $fields);
	}

	/**
	 * @phpstan-param CameraInstructionFields $fields
	 */
	public static function cameraInstruction(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CAMERA_INSTRUCTION, $fields);
	}

	/**
	 * @phpstan-param TrimDataFields $fields
	 */
	public static function trimData(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::TRIM_DATA, $fields);
	}

	/**
	 * @phpstan-param OpenSignFields $fields
	 */
	public static function openSign(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::OPEN_SIGN, $fields);
	}

	/**
	 * @phpstan-param AgentAnimationFields $fields
	 */
	public static function agentAnimation(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::AGENT_ANIMATION, $fields);
	}

	/**
	 * @phpstan-param RefreshEntitlementsFields $fields
	 */
	public static function refreshEntitlements(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::REFRESH_ENTITLEMENTS, $fields);
	}

	/**
	 * @phpstan-param PlayerToggleCrafterSlotRequestFields $fields
	 */
	public static function playerToggleCrafterSlotRequest(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PLAYER_TOGGLE_CRAFTER_SLOT_REQUEST, $fields);
	}

	/**
	 * @phpstan-param SetPlayerInventoryOptionsFields $fields
	 */
	public static function setPlayerInventoryOptions(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SET_PLAYER_INVENTORY_OPTIONS, $fields);
	}

	/**
	 * @phpstan-param SetHudFields $fields
	 */
	public static function setHud(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SET_HUD, $fields);
	}

	/**
	 * @phpstan-param AwardAchievementFields $fields
	 */
	public static function awardAchievement(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::AWARD_ACHIEVEMENT, $fields);
	}

	/**
	 * @phpstan-param ClientBoundCloseFormFields $fields
	 */
	public static function clientBoundCloseForm(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CLIENT_BOUND_CLOSE_FORM, $fields);
	}

	/**
	 * @phpstan-param ServerBoundLoadingScreenFields $fields
	 */
	public static function serverBoundLoadingScreen(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SERVER_BOUND_LOADING_SCREEN, $fields);
	}

	/**
	 * @phpstan-param JigsawStructureDataFields $fields
	 */
	public static function jigsawStructureData(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::JIGSAW_STRUCTURE_DATA, $fields);
	}

	/**
	 * @phpstan-param CurrentStructureFeatureFields $fields
	 */
	public static function currentStructureFeature(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CURRENT_STRUCTURE_FEATURE, $fields);
	}

	/**
	 * @phpstan-param ServerBoundDiagnosticsFields $fields
	 */
	public static function serverBoundDiagnostics(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SERVER_BOUND_DIAGNOSTICS, $fields);
	}

	/**
	 * @phpstan-param CameraAimAssistFields $fields
	 */
	public static function cameraAimAssist(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CAMERA_AIM_ASSIST, $fields);
	}

	/**
	 * @phpstan-param ContainerRegistryCleanupFields $fields
	 */
	public static function containerRegistryCleanup(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CONTAINER_REGISTRY_CLEANUP, $fields);
	}

	/**
	 * @phpstan-param MovementEffectFields $fields
	 */
	public static function movementEffect(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::MOVEMENT_EFFECT, $fields);
	}

	/**
	 * @phpstan-param CameraAimAssistPresetsFields $fields
	 */
	public static function cameraAimAssistPresets(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CAMERA_AIM_ASSIST_PRESETS, $fields);
	}

	/**
	 * @phpstan-param ClientCameraAimAssistFields $fields
	 */
	public static function clientCameraAimAssist(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CLIENT_CAMERA_AIM_ASSIST, $fields);
	}

	/**
	 * @phpstan-param ClientMovementPredictionSyncFields $fields
	 */
	public static function clientMovementPredictionSync(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CLIENT_MOVEMENT_PREDICTION_SYNC, $fields);
	}

	/**
	 * @phpstan-param UpdateClientOptionsFields $fields
	 */
	public static function updateClientOptions(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::UPDATE_CLIENT_OPTIONS, $fields);
	}

	/**
	 * @phpstan-param PlayerVideoCaptureFields $fields
	 */
	public static function playerVideoCapture(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PLAYER_VIDEO_CAPTURE, $fields);
	}

	/**
	 * @phpstan-param PlayerUpdateEntityOverridesFields $fields
	 */
	public static function playerUpdateEntityOverrides(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PLAYER_UPDATE_ENTITY_OVERRIDES, $fields);
	}

	/**
	 * @phpstan-param PlayerLocationFields $fields
	 */
	public static function playerLocation(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PLAYER_LOCATION, $fields);
	}

	/**
	 * @phpstan-param ClientBoundControlSchemeSetFields $fields
	 */
	public static function clientBoundControlSchemeSet(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CLIENT_BOUND_CONTROL_SCHEME_SET, $fields);
	}

	/**
	 * @phpstan-param PrimitiveShapesFields $fields
	 */
	public static function primitiveShapes(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PRIMITIVE_SHAPES, $fields);
	}

	/**
	 * @phpstan-param ServerBoundPackSettingChangeFields $fields
	 */
	public static function serverBoundPackSettingChange(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SERVER_BOUND_PACK_SETTING_CHANGE, $fields);
	}

	/**
	 * @phpstan-param ClientBoundDataStoreFields $fields
	 */
	public static function clientBoundDataStore(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CLIENT_BOUND_DATA_STORE, $fields);
	}

	/**
	 * @phpstan-param GraphicsOverrideParameterFields $fields
	 */
	public static function graphicsOverrideParameter(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::GRAPHICS_OVERRIDE_PARAMETER, $fields);
	}

	/**
	 * @phpstan-param ServerBoundDataStoreFields $fields
	 */
	public static function serverBoundDataStore(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SERVER_BOUND_DATA_STORE, $fields);
	}

	/**
	 * @phpstan-param ClientBoundDataDrivenUiShowScreenFields $fields
	 */
	public static function clientBoundDataDrivenUiShowScreen(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CLIENT_BOUND_DATA_DRIVEN_UI_SHOW_SCREEN, $fields);
	}

	/**
	 * @phpstan-param ClientBoundDataDrivenUiCloseScreenFields $fields
	 */
	public static function clientBoundDataDrivenUiCloseScreen(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CLIENT_BOUND_DATA_DRIVEN_UI_CLOSE_SCREEN, $fields);
	}

	/**
	 * @phpstan-param ClientBoundDataDrivenUiReloadFields $fields
	 */
	public static function clientBoundDataDrivenUiReload(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CLIENT_BOUND_DATA_DRIVEN_UI_RELOAD, $fields);
	}

	/**
	 * @phpstan-param ClientBoundTextureShiftFields $fields
	 */
	public static function clientBoundTextureShift(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CLIENT_BOUND_TEXTURE_SHIFT, $fields);
	}

	/**
	 * @phpstan-param VoxelShapesFields $fields
	 */
	public static function voxelShapes(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::VOXEL_SHAPES, $fields);
	}

	/**
	 * @phpstan-param CameraSplineFields $fields
	 */
	public static function cameraSpline(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CAMERA_SPLINE, $fields);
	}

	/**
	 * @phpstan-param CameraAimAssistActorPriorityFields $fields
	 */
	public static function cameraAimAssistActorPriority(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CAMERA_AIM_ASSIST_ACTOR_PRIORITY, $fields);
	}

	/**
	 * @phpstan-param ResourcePacksReadyForValidationFields $fields
	 */
	public static function resourcePacksReadyForValidation(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::RESOURCE_PACKS_READY_FOR_VALIDATION, $fields);
	}

	/**
	 * @phpstan-param LocatorBarFields $fields
	 */
	public static function locatorBar(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::LOCATOR_BAR, $fields);
	}

	/**
	 * @phpstan-param PartyChangedFields $fields
	 */
	public static function partyChanged(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PARTY_CHANGED, $fields);
	}

	/**
	 * @phpstan-param ServerBoundDataDrivenScreenClosedFields $fields
	 */
	public static function serverBoundDataDrivenScreenClosed(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SERVER_BOUND_DATA_DRIVEN_SCREEN_CLOSED, $fields);
	}

	/**
	 * @phpstan-param SyncWorldClocksFields $fields
	 */
	public static function syncWorldClocks(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SYNC_WORLD_CLOCKS, $fields);
	}

	/**
	 * @phpstan-param ClientBoundAttributeLayerSyncFields $fields
	 */
	public static function clientBoundAttributeLayerSync(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CLIENT_BOUND_ATTRIBUTE_LAYER_SYNC, $fields);
	}

	/**
	 * @phpstan-param ServerStoreInfoFields $fields
	 */
	public static function serverStoreInfo(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SERVER_STORE_INFO, $fields);
	}

	/**
	 * @phpstan-param ServerPresenceInfoFields $fields
	 */
	public static function serverPresenceInfo(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SERVER_PRESENCE_INFO, $fields);
	}

	/**
	 * @phpstan-param ClientboundUpdateSoundDataFields $fields
	 */
	public static function clientboundUpdateSoundData(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::CLIENTBOUND_UPDATE_SOUND_DATA, $fields);
	}

	/**
	 * @phpstan-param SendPartyDestinationCookieFields $fields
	 */
	public static function sendPartyDestinationCookie(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SEND_PARTY_DESTINATION_COOKIE, $fields);
	}

	/**
	 * @phpstan-param PartyDestinationCookieResponseFields $fields
	 */
	public static function partyDestinationCookieResponse(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::PARTY_DESTINATION_COOKIE_RESPONSE, $fields);
	}

	/**
	 * @phpstan-param SetPlayerFurnaceOptionsFields $fields
	 */
	public static function setPlayerFurnaceOptions(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::SET_PLAYER_FURNACE_OPTIONS, $fields);
	}

	/**
	 * @phpstan-param RecordStartedFields $fields
	 */
	public static function recordStarted(array $fields) : ArrayPacket{
		return new ArrayPacket(PacketId::RECORD_STARTED, $fields);
	}
}
