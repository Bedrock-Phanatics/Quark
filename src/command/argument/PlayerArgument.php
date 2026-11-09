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

namespace quark\command\argument;

use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use quark\player\Player;
use function str_starts_with;

/**
 * @phpstan-extends Argument<Player>
 */
final class PlayerArgument extends Argument{

	/**
	 * @param bool $partial accept the start of a name when it matches one online player
	 * @param bool $self   accept "@s" for the sender when it is a player
	 */
	public function __construct(
		string $name,
		bool $optional = false,
		private bool $suggest = true,
		private bool $partial = false,
		private bool $self = false
	){
		parent::__construct($name, $optional);
	}

	public function parse(string $input, CommandSender $sender) : Player{
		if($this->self && $input === "@s"){
			return $sender instanceof Player ? $sender : throw new ArgumentParseException(KnownTranslationFactory::quark_command_error_playerNotFound($input));
		}
		if(str_starts_with($input, "@")){
			throw new ArgumentParseException(KnownTranslationFactory::quark_command_error_selectorUnsupported($input));
		}
		$server = $sender->getServer();
		return ($this->partial ? $server->getPlayerByPrefix($input) : $server->getPlayerExact($input)) ??
			throw new ArgumentParseException(KnownTranslationFactory::quark_command_error_playerNotFound($input));
	}

	public function getKind() : ArgumentKind{
		return $this->suggest ? ArgumentKind::PLAYER : ArgumentKind::WORD;
	}

	public function getValueType() : string{
		return Player::class;
	}
}
