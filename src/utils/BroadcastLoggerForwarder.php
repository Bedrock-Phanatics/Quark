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

namespace quark\utils;

use quark\command\CommandSender;
use quark\lang\Language;
use quark\lang\Translatable;
use quark\permission\PermissibleBase;
use quark\permission\PermissibleDelegateTrait;
use quark\Server;
use const PHP_INT_MAX;

/**
 * Forwards any messages it receives via sendMessage() to the given logger. Used for forwarding chat messages and
 * command audit log messages to the server log file.
 *
 * Unfortunately, broadcast subscribers are currently required to implement CommandSender, so this class has to include
 * a lot of useless methods.
 */
final class BroadcastLoggerForwarder implements CommandSender{
	use PermissibleDelegateTrait;

	public function __construct(
		private Server $server, //annoying useless dependency
		private \Logger $logger,
		private Language $language
	){
		//this doesn't need any permissions
		$this->perm = new PermissibleBase([]);
	}

	public function getLanguage() : Language{
		return $this->language;
	}

	public function sendMessage(Translatable|string $message) : void{
		if($message instanceof Translatable){
			$this->logger->info($this->language->translate($message));
		}else{
			$this->logger->info($message);
		}
	}

	public function getServer() : Server{
		return $this->server;
	}

	public function getName() : string{
		return "Broadcast Logger Forwarder";
	}

	public function getScreenLineHeight() : int{
		return PHP_INT_MAX;
	}

	public function setScreenLineHeight(?int $height) : void{
		//NOOP
	}
}
