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
use pocketmine\math\Vector2;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\EditorWorldType;
use pocketmine\network\mcpe\protocol\types\GeneratorType;
use pocketmine\network\mcpe\protocol\types\MultiplayerGameVisibility;
use pocketmine\network\mcpe\protocol\types\PlayerPermissions;
use pocketmine\network\mcpe\protocol\types\SpawnSettings as BedrockSpawnSettings;
use quark\network\mcpe\cache\CraftingDataCache;
use quark\network\mcpe\cache\StaticPacketCache;
use quark\network\mcpe\InventoryManager;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\Fields;
use quark\network\mcpe\protocol\packet\ItemRegistryPacket;
use quark\network\mcpe\protocol\packet\RequestChunkRadiusPacket;
use quark\network\mcpe\protocol\packet\StartGamePacket;
use quark\network\mcpe\protocol\types\EducationSharedResourceURI;
use quark\network\mcpe\protocol\types\EventsServerTelemetryData;
use quark\network\mcpe\protocol\types\Experiments;
use quark\network\mcpe\protocol\types\GameRule;
use quark\network\mcpe\protocol\types\ItemData;
use quark\network\mcpe\protocol\types\LevelSettings;
use quark\network\mcpe\protocol\types\PlayerMovementSettings;
use quark\network\mcpe\protocol\types\SpawnSettings;
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
			$disabledGameRule = fn(string $name) => new GameRule($name, false, ["bool" => false]);
			$this->session->sendDataPacket(new StartGamePacket(
				entityId: $this->player->getId(),
				runtimeId: $this->player->getId(),
				gameType: $typeConverter->coreGameModeToProtocol($this->player->getGamemode())->value,
				position: $this->player->getOffsetPosition($location),
				rotation: new Vector2($location->pitch, $location->yaw),
				settings: new LevelSettings(
					seed: -1,
					spawnSettings: new SpawnSettings(spawnBiomeType: BedrockSpawnSettings::BIOME_TYPE_DEFAULT, userDefinedBiomeName: "", dimension: DimensionIds::OVERWORLD), //TODO: implement this properly
					generatorType: GeneratorType::OVERWORLD,
					gameType: $typeConverter->coreGameModeToProtocol($this->server->getGamemode())->value,
					isHardcore: false,
					gameDifficulty: $world->getDifficulty(),
					defaultSpawnBlockPosition: $world->getSpawnLocation()->floor(),
					achievementsDisabled: true,
					editorWorldType: EditorWorldType::NON_EDITOR,
					isCreatedInEditor: false,
					isExportedFromEditor: false,
					dayCycleStopTime: $world->getTime(),
					educationEditionOffer: 0,
					educationFeaturesEnabled: false,
					educationProductId: "",
					rainLevel: 0.0, //TODO: implement these properly
					lightningLevel: 0.0,
					hasConfirmedPlatformLockedContent: false,
					multiplayerGameIntent: true,
					lanBroadcastIntent: true,
					xboxLiveBroadcastSetting: MultiplayerGameVisibility::PUBLIC,
					platformBroadcastSetting: MultiplayerGameVisibility::PUBLIC,
					commandsEnabled: true,
					texturePacksRequired: true,
					ruleData: [
						$disabledGameRule("naturalregeneration"), //Hack for client side regeneration
						$disabledGameRule("locatorbar"), //Disable client-side tracking of nearby players
					],
					experiments: new Experiments(toggles: [], experimentsEverToggled: false),
					hasBonusChestEnabled: false,
					startWithMapEnabled: false,
					playerPermissions: PlayerPermissions::MEMBER->value,
					serverChunkTickRange: 4,
					hasLockedBehaviorPack: false,
					hasLockedResourcePack: false,
					isFromLockedTemplate: false,
					useMsaGamertagsOnly: false,
					isFromWorldTemplate: false,
					isWorldTemplateOptionLocked: false,
					onlySpawnV1Villagers: false,
					personaDisabled: false,
					customSkinsDisabled: false,
					emoteChatMuted: false,
					baseGameVersion: ProtocolInfo::MINECRAFT_VERSION_NETWORK,
					limitedWorldWidth: 0,
					limitedWorldDepth: 0,
					netherType: true,
					eduSharedUriResource: new EducationSharedResourceURI(buttonName: "", linkUri: ""),
					overrideForceExperimentalGameplay: null,
					chatRestrictionLevel: 0,
					disablePlayerInteractions: false,
					serverEditorConnectionPolicy: 0,
					allowAnonymousBlockDropsInEditorWorlds: false,
				),
				levelId: "",
				levelName: $this->server->getMotd(),
				templateContentIdentity: "",
				isTrial: false,
				movementSettings: new PlayerMovementSettings(rewindHistorySize: 0, serverAuthoritativeBlockBreaking: true),
				levelCurrentTime: 0,
				enchantmentSeed: 0,
				blockProperties: StaticPacketCache::getInstance()->getBlockPaletteEntries(),
				multiplayerCorrelationId: "",
				enableItemStackNetManager: true,
				serverVersion: sprintf("%s %s", VersionInfo::NAME, VersionInfo::VERSION()->getFullVersion(true)),
				playerPropertyData: self::EMPTY_COMPOUND, //TODO: we don't care about this right now
				serverBlockTypeRegistryChecksum: 0,
				worldTemplateId: Fields::uuid(Uuid::fromString(Uuid::NIL)),
				serverEnabledClientSideGeneration: false,
				blockNetworkIdsAreHashes: false,
				networkPermissions: true, //disables client sounds
				serverConfigurationJoinInfo: null,
				serverTelemetryData: new EventsServerTelemetryData(serverId: "", scenarioId: "", worldId: "", ownerId: ""),
			));

			$this->session->getLogger()->debug("Sending items");
			$items = [];
			foreach($typeConverter->getItemTypeDictionary()->getEntries() as $entry){
				$items[] = new ItemData(
					itemName: $entry->getStringId(),
					itemId: $entry->getNumericId(),
					isComponentBased: $entry->isComponentBased(),
					itemVersion: $entry->getVersion(),
					itemComponentData: $entry->getComponentNbt()->getEncodedNbt(),
				);
			}
			$this->session->sendDataPacket(new ItemRegistryPacket(itemData: $items));

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
