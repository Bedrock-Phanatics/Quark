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

use quark\entity\Human;
use quark\event\Cancellable;
use quark\event\CancellableTrait;
use quark\player\Player;
use function strlen;

/**
 * Called when a player uses an emote.
 */
class PlayerEmoteEvent extends PlayerEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		Player $player,
		private string $emoteId
	){
		$this->player = $player;
	}

	public function getEmoteId() : string{
		return $this->emoteId;
	}

	public function setEmoteId(string $emoteId) : void{
		if(strlen($emoteId) > Human::MAX_EMOTE_ID_BYTES){
			throw new \InvalidArgumentException("Emote ID must be at most " . Human::MAX_EMOTE_ID_BYTES . " bytes long");
		}
		$this->emoteId = $emoteId;
	}

}
