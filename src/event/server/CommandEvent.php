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

namespace quark\event\server;

use quark\command\CommandSender;
use quark\event\Cancellable;
use quark\event\CancellableTrait;

/**
 * Called when any CommandSender runs a command, before it is parsed.
 *
 * This can be used for logging commands, or preprocessing the command string to add custom features (e.g. selectors).
 *
 * WARNING: DO NOT use this to block commands. Many commands have aliases.
 * For example, /version can also be invoked using /ver or /about.
 * To prevent command senders from using certain commands, deny them permission to use the commands you don't want them
 * to have access to.
 *
 * @see Permissible::addAttachment()
 *
 * The message DOES NOT begin with a slash.
 */
class CommandEvent extends ServerEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		protected CommandSender $sender,
		protected string $command
	){}

	public function getSender() : CommandSender{
		return $this->sender;
	}

	public function getCommand() : string{
		return $this->command;
	}

	public function setCommand(string $command) : void{
		$this->command = $command;
	}
}
