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
use quark\network\mcpe\protocol\packet\SetLocalPlayerAsInitialisedPacket;

#[SilentDiscard(PacketId::EMOTE_LIST, comment: "Probably not needed?")]
#[SilentDiscard(PacketId::INTERACT, comment: "Player interacting with itself somehow")]
#[SilentDiscard(PacketId::MOB_EQUIPMENT, comment: "Player equipping its held item on spawn, not needed")]
#[SilentDiscard(PacketId::PLAYER_AUTH_INPUT, comment: "Spammed after StartGame even though player has no controls")]
#[SilentDiscard(PacketId::SERVER_BOUND_LOADING_SCREEN, comment: "Not used, arrives with SetLocalPlayerAsInitialized")]
final class SpawnResponsePacketHandler extends PacketHandler{
	/**
	 * @phpstan-param \Closure() : void $responseCallback
	 */
	public function __construct(private \Closure $responseCallback){}

	public function handleSetLocalPlayerAsInitialised(SetLocalPlayerAsInitialisedPacket $packet) : bool{
		($this->responseCallback)();
		return true;
	}
}
