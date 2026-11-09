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
use function filter_var;
use const FILTER_VALIDATE_IP;

/**
 * An IPv4 or IPv6 address. With $orPlayer it also accepts the start of an online player's name and yields that player.
 *
 * @phpstan-extends Argument<mixed>
 */
final class IpAddressArgument extends Argument{

	public function __construct(string $name, bool $optional = false, private bool $orPlayer = false){
		parent::__construct($name, $optional);
	}

	public function parse(string $input, CommandSender $sender) : mixed{
		if(filter_var($input, FILTER_VALIDATE_IP) !== false){
			return $input;
		}
		if($this->orPlayer){
			return $sender->getServer()->getPlayerByPrefix($input) ?? throw new ArgumentParseException(KnownTranslationFactory::commands_banip_invalid());
		}
		throw new ArgumentParseException(KnownTranslationFactory::commands_unbanip_invalid());
	}

	public function getValueType() : string{
		return $this->orPlayer ? "mixed" : "string";
	}
}
