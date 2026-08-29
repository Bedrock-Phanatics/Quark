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

/**
 * Called when a player connects with a username or UUID that is already used by another player on the server.
 * If cancelled, the newly connecting session will be disconnected; otherwise, the existing player will be disconnected.
 */
class PlayerDuplicateLoginEvent extends Event implements Cancellable{
	use CancellableTrait;
	use PlayerDisconnectEventTrait;

	public function __construct(
		private NetworkSession $connectingSession,
		private NetworkSession $existingSession,
		private Translatable|string $disconnectReason,
		private Translatable|string|null $disconnectScreenMessage
	){}

	public function getConnectingSession() : NetworkSession{
		return $this->connectingSession;
	}

	public function getExistingSession() : NetworkSession{
		return $this->existingSession;
	}
}
