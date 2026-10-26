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

use pocketmine\network\mcpe\protocol\RespawnPacket;
use pocketmine\network\mcpe\protocol\types\PlayerAction;
use quark\lang\Translatable;
use quark\network\mcpe\InventoryManager;
use quark\network\mcpe\NetworkSession;
use quark\network\mcpe\protocol\Fields;
use quark\network\mcpe\protocol\Packets;
use quark\player\Player;

/**
 * @phpstan-import-type ContainerCloseFields from Packets
 * @phpstan-import-type PlayerActionFields from Packets
 * @phpstan-import-type RespawnFields from Packets
 */
class DeathPacketHandler extends PacketHandler{
	public function __construct(
		private Player $player,
		private NetworkSession $session,
		private InventoryManager $inventoryManager,
		private Translatable|string $deathMessage
	){}

	public function setUp() : void{
		$this->session->sendDataPacket(Packets::respawn([
			"position" => Fields::vec3($this->player->getOffsetPosition($this->player->getSpawn())),
			"state" => RespawnPacket::SEARCHING_FOR_SPAWN,
			"player_runtime_id" => $this->player->getId(),
		]));

		/** @var list<string> $parameters */
		$parameters = [];
		if($this->deathMessage instanceof Translatable){
			$language = $this->player->getLanguage();
			if(!$this->player->getServer()->isLanguageForced()){
				[$message, $parameters] = $this->session->prepareClientTranslatableMessage($this->deathMessage);
			}else{
				$message = $language->translate($this->deathMessage);
			}
		}else{
			$message = $this->deathMessage;
		}
		$this->session->sendDataPacket(Packets::deathInfo(["death_cause_attack_name" => $message, "death_cause_message_list" => $parameters]));
	}

	/**
	 * @phpstan-param PlayerActionFields $packet
	 */
	public function handlePlayerAction(array $packet) : bool{
		if($packet["action"] === PlayerAction::RESPAWN){
			$this->player->respawn();
			return true;
		}

		return false;
	}

	/**
	 * @phpstan-param ContainerCloseFields $packet
	 */
	public function handleContainerClose(array $packet) : bool{
		$this->inventoryManager->onClientRemoveWindow($packet["container_id"]);
		return true;
	}

	/**
	 * @phpstan-param RespawnFields $packet
	 */
	public function handleRespawn(array $packet) : bool{
		if($packet["state"] === RespawnPacket::CLIENT_READY_TO_SPAWN){
			$this->session->sendDataPacket(Packets::respawn([
				"position" => Fields::vec3($this->player->getOffsetPosition($this->player->getSpawn())),
				"state" => RespawnPacket::READY_TO_SPAWN,
				"player_runtime_id" => $this->player->getId(),
			]));
			return true;
		}
		return false;
	}
}
