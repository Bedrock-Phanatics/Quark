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

namespace quark\console;

use quark\command\CommandSender;
use quark\lang\Language;
use quark\lang\Translatable;
use quark\permission\DefaultPermissions;
use quark\permission\PermissibleBase;
use quark\permission\PermissibleDelegateTrait;
use quark\Server;
use quark\utils\Terminal;
use quark\utils\TextFormat;
use function explode;
use function trim;
use const PHP_INT_MAX;

class ConsoleCommandSender implements CommandSender{
	use PermissibleDelegateTrait;

	/** @phpstan-var positive-int|null */
	protected ?int $lineHeight = null;

	public function __construct(
		private Server $server,
		private Language $language
	){
		$this->perm = new PermissibleBase([DefaultPermissions::ROOT_CONSOLE => true]);
	}

	public function getServer() : Server{
		return $this->server;
	}

	public function getLanguage() : Language{
		return $this->language;
	}

	public function sendMessage(Translatable|string $message) : void{
		if($message instanceof Translatable){
			$message = $this->getLanguage()->translate($message);
		}

		foreach(explode("\n", trim($message), limit: PHP_INT_MAX) as $line){
			Terminal::writeLine(TextFormat::GREEN . "Command output | " . TextFormat::addBase(TextFormat::WHITE, $line));
		}
	}

	public function getName() : string{
		return "CONSOLE";
	}

	public function getScreenLineHeight() : int{
		return $this->lineHeight ?? PHP_INT_MAX;
	}

	public function setScreenLineHeight(?int $height) : void{
		if($height !== null && $height < 1){
			throw new \InvalidArgumentException("Line height must be at least 1");
		}
		$this->lineHeight = $height;
	}
}
