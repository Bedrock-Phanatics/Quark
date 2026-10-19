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

namespace quark\event\player;

use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\event\Event;
use quark\lang\Translatable;
use quark\network\mcpe\NetworkSession;
use quark\network\TransportIdentityKey;
use quark\player\PlayerInfo;

/**
 * Called after the server has verified the player's login (JWT chain, and the transport identity key if the transport
 * provides one), before the player is created. Plugins can perform their own identity checks here.
 * Cancelling the event disconnects the player with the disconnect reason set.
 */
class PlayerIdentityVerifyEvent extends Event implements Cancellable{
	use CancellableTrait;
	use PlayerDisconnectEventTrait;

	public function __construct(
		private NetworkSession $session,
		private PlayerInfo $playerInfo,
		private bool $authenticated,
		private bool $authRequired,
		private string $clientPublicKey,
		private ?TransportIdentityKey $transportIdentityKey,
		private Translatable|string $disconnectReason,
		private Translatable|string|null $disconnectScreenMessage
	){}

	public function getSession() : NetworkSession{
		return $this->session;
	}

	/**
	 * Returns information about the player. Unlike PlayerPreLoginEvent, the XUID has been verified if
	 * {@link isAuthenticated()} is true. If the player is not authenticated, any unverified Xbox Live
	 * data (including XUID) has been stripped.
	 */
	public function getPlayerInfo() : PlayerInfo{
		return $this->playerInfo;
	}

	/**
	 * Returns whether the player is signed into Xbox Live.
	 */
	public function isAuthenticated() : bool{
		return $this->authenticated;
	}

	/**
	 * Returns whether the server requires Xbox Live authentication for this player, as determined by the server settings
	 * and PlayerPreLoginEvent. If this is true, the player is authenticated; otherwise, they may or may not be authenticated.
	 */
	public function isAuthRequired() : bool{
		return $this->authRequired;
	}

	/**
	 * Returns the DER-encoded public key from the login chain.
	 */
	public function getClientPublicKey() : string{
		return $this->clientPublicKey;
	}

	public function getTransportIdentityKey() : ?TransportIdentityKey{
		return $this->transportIdentityKey;
	}
}
