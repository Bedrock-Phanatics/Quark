<?php

/*
 * This file is part of Quark.
 * Copyright (C) Quark Team <https://github.com/Bedrock-Phanatics/Quark>
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace quark\network\mcpe;

use pocketmine\network\mcpe\protocol\AddActorPacket;
use pocketmine\network\mcpe\protocol\AddPlayerPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\NpcDialoguePacket;
use pocketmine\network\mcpe\protocol\NpcRequestPacket;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\RemoveActorPacket;
use pocketmine\network\mcpe\protocol\types\AbilitiesData;
use pocketmine\network\mcpe\protocol\types\AbilitiesLayer;
use pocketmine\network\mcpe\protocol\types\command\CommandPermissions;
use pocketmine\network\mcpe\protocol\types\DeviceOS;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataFlags;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\network\mcpe\protocol\types\GameMode;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStack;
use pocketmine\network\mcpe\protocol\types\inventory\ItemStackWrapper;
use pocketmine\network\mcpe\protocol\types\PlayerListEntry;
use pocketmine\network\mcpe\protocol\types\PlayerPermissions;
use quark\entity\Entity;
use quark\math\Vector3;
use quark\npcdialogue\NpcDialogue;
use quark\npcdialogue\NpcDialogueSession;
use quark\player\Player;
use quark\plugin\Plugin;
use Ramsey\Uuid\Uuid;
use function array_fill;
use function bin2hex;
use function random_bytes;
use function spl_object_id;

/**
 * @internal
 */
final class NpcDialogueController{
	/** @var \WeakReference<NetworkSession> */
	private readonly \WeakReference $network;
	private ?NpcDialogueSession $active = null;
	private bool $sending = false;
	private int $context = 0;

	public function __construct(NetworkSession $network){
		$this->network = \WeakReference::create($network);
	}

	public function getActive() : ?NpcDialogueSession{ return $this->active; }

	public function open(NpcDialogue $dialogue, ?Entity $npc = null) : ?NpcDialogueSession{
		return $this->show($dialogue, $npc, false);
	}

	public function update(NpcDialogueSession $session, NpcDialogue $dialogue) : ?NpcDialogueSession{
		$previous = $session->getDefinition();
		if($this->active !== $session || $previous === null || $dialogue->getOwner() !== $previous->getOwner()){
			return null;
		}
		$npc = null;
		if(!$session->virtualActor){
			$npc = $this->network->get()?->getPlayer()?->getWorld()->getEntity($session->actorId);
			if($npc === null){
				return null;
			}
		}

		$reuseActor = $npc !== null || (
			$previous->getEntityType() === $dialogue->getEntityType() &&
			$previous->getVariant() === $dialogue->getVariant() &&
			$previous->getSkin() === $dialogue->getSkin() &&
			$previous->getOffsetJson() === $dialogue->getOffsetJson()
		);
		return $this->show($dialogue, $npc, $reuseActor);
	}

	private function show(NpcDialogue $dialogue, ?Entity $npc, bool $reuseActor) : ?NpcDialogueSession{
		$network = $this->network->get();
		$player = $network?->getPlayer();
		if($this->sending || $network === null || $player === null || !self::canOpen($network, $player, $dialogue, $npc)){
			return null;
		}

		$previous = $this->active;
		$actorId = $npc?->getId() ?? ($reuseActor ? $previous?->actorId : null) ?? Entity::nextRuntimeId();
		$scene = bin2hex(random_bytes(16));
		$actionJson = $dialogue->getActionJson();
		$snapshot = clone $dialogue;
		$packets = $previous !== null ? self::closingPackets($previous, true, $previous->actorId !== $actorId) : [];
		if($npc === null && !$reuseActor){
			foreach(self::spawnPackets($network, $player, $snapshot, $actorId) as $packet){
				$packets[] = $packet;
			}
		}
		$packets[] = NpcDialoguePacket::create($actorId, NpcDialoguePacket::ACTION_OPEN, $snapshot->getText(), $scene, $snapshot->getName(), $actionJson);

		$context = $this->context;
		$this->sending = true;
		try{
			if(!$network->sendDataPacketGroup($packets, fn() => $this->context === $context && self::canOpen($network, $player, $snapshot, $npc))){
				return null;
			}
			$previous?->retire();
			return $this->active = new NpcDialogueSession($this, $snapshot, $actorId, $scene, $npc === null);
		}finally{
			$this->sending = false;
		}
	}

	private static function canOpen(NetworkSession $network, Player $player, NpcDialogue $dialogue, ?Entity $npc) : bool{
		return $network->isConnected() && $player->spawned && $player->isAlive() && !$network->isChangingDimension() &&
			$dialogue->getOwner()->isEnabled() && ($npc === null || self::canUseNpc($npc, $player));
	}

	private static function canUseNpc(Entity $npc, Player $player) : bool{
		return !$npc->isClosed() && $npc->isAlive() && !$npc->isFlaggedForDespawn() &&
			$npc->getNetworkTypeId() === EntityIds::NPC && $npc->getWorld() === $player->getWorld() &&
			isset($npc->getViewers()[spl_object_id($player)]);
	}

	public function handleResponse(NpcRequestPacket $packet) : void{
		$session = $this->active;
		if($this->sending || $session === null || $packet->actorRuntimeId !== $session->actorId || $packet->sceneName !== $session->scene){
			return;
		}

		$closing = $packet->requestType === NpcRequestPacket::REQUEST_EXECUTE_CLOSING_COMMANDS;
		if(!$closing && ($packet->requestType !== NpcRequestPacket::REQUEST_EXECUTE_ACTION || $session->getDefinition()?->hasButton($packet->actionIndex) !== true)){
			return;
		}

		if(!$session->virtualActor){
			$player = $this->network->get()?->getPlayer();
			$npc = $player?->getWorld()->getEntity($session->actorId);
			if($player === null || $npc === null || !self::canUseNpc($npc, $player)){
				$this->reset();
				return;
			}
		}
		$this->finish($session, true, !$closing, $closing ? null : $packet->actionIndex);
	}

	public function close(NpcDialogueSession $session) : bool{
		if($this->sending || $this->active !== $session){
			return false;
		}
		$this->finish($session, true, true);
		return true;
	}

	public function cancelForOwner(Plugin $owner) : void{
		if($this->active?->getDefinition()?->getOwner() === $owner){
			$this->reset();
		}
	}

	public function onActorRemoved(int $actorId) : void{
		if($this->active?->actorId === $actorId){
			$this->reset();
		}
	}

	public function reset() : void{
		++$this->context;
		if($this->active !== null){
			$this->finish($this->active, false, true);
		}
	}

	private function finish(NpcDialogueSession $session, bool $notify, bool $closeScreen, ?int $button = null) : void{
		$dialogue = $session->getDefinition();
		$this->active = null;
		$session->retire();

		$network = $this->network->get();
		if($network === null || !$network->isConnected()){
			return;
		}

		// Once the session is retired, a veto would strand its screen or portrait on the client.
		if(($packets = self::closingPackets($session, $closeScreen, true)) !== []){
			$network->addToSendBuffer($packets);
		}

		if($notify && $dialogue !== null && ($player = $network->getPlayer()) !== null && $dialogue->getOwner()->isEnabled()){
			try{
				if($button === null){
					$dialogue->invokeClose($player);
				}else{
					$dialogue->invokeButton($player, $button);
				}
			}catch(\Throwable $e){
				$network->getLogger()->logException($e);
			}
		}
	}

	/** @return list<ClientboundPacket> */
	private static function closingPackets(NpcDialogueSession $session, bool $closeScreen, bool $removeActor) : array{
		$packets = [];
		if($closeScreen){
			$packets[] = NpcDialoguePacket::create($session->actorId, NpcDialoguePacket::ACTION_CLOSE, "", $session->scene, "", "");
		}
		if($removeActor && $session->virtualActor){
			$packets[] = RemoveActorPacket::create($session->actorId);
		}
		return $packets;
	}

	/** @return list<ClientboundPacket> */
	private static function spawnPackets(NetworkSession $network, Player $player, NpcDialogue $dialogue, int $actorId) : array{
		$metadata = new EntityMetadataCollection();
		$metadata->setGenericFlag(EntityMetadataFlags::IMMOBILE, true);
		$metadata->setGenericFlag(EntityMetadataFlags::SILENT, true);
		$metadata->setByte(EntityMetadataProperties::HAS_NPC_COMPONENT, 1);
		$metadata->setFloat(EntityMetadataProperties::BOUNDING_BOX_WIDTH, 0);
		$metadata->setFloat(EntityMetadataProperties::BOUNDING_BOX_HEIGHT, 0);
		$position = $player->getPosition()->add(0, -16, 0);
		$properties = new PropertySyncData([], []);
		$skin = $dialogue->getSkin();
		$offsetJson = $dialogue->getOffsetJson();
		if($skin !== null){
			$offsetJson ??= '{"picker_offsets":{"scale":[0,0,0],"translate":[0,0,0]},"portrait_offsets":{"scale":[1,1,1],"translate":[0,-50,0]}}';
		}
		if($offsetJson !== null){
			$metadata->setString(EntityMetadataProperties::NPC_SKIN_INDEX, $offsetJson);
		}

		if($skin === null){
			if($dialogue->getEntityType() === EntityIds::NPC){
				$metadata->setInt(EntityMetadataProperties::VARIANT, $dialogue->getVariant());
			}
			return [AddActorPacket::create($actorId, $actorId, $dialogue->getEntityType(), $position, null, 0, 0, 0, 0, [], $metadata->getAll(), $properties, [], null)];
		}

		$uuid = Uuid::uuid4();
		$skinData = $network->getTypeConverter()->getSkinAdapter()->toSkinData($skin);
		return [
			PlayerListPacket::create([PlayerListEntry::createAdditionEntry($uuid, $actorId, "", $skinData)]),
			AddPlayerPacket::create(
				$uuid,
				"",
				$actorId,
				"",
				$position,
				Vector3::zero(),
				0,
				0,
				0,
				ItemStackWrapper::legacy(ItemStack::null()),
				GameMode::SURVIVAL,
				$metadata->getAll(),
				$properties,
				new AbilitiesData(CommandPermissions::NORMAL, PlayerPermissions::VISITOR, $actorId, [
					new AbilitiesLayer(AbilitiesLayer::LAYER_BASE, array_fill(0, AbilitiesLayer::NUMBER_OF_ABILITIES, false), 0, 0, 0)
				]),
				[],
				null,
				"",
				DeviceOS::UNKNOWN
			),
			PlayerListPacket::create([PlayerListEntry::createRemovalEntry($uuid)])
		];
	}
}
