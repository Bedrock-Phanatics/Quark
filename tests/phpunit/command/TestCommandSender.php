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

namespace quark\command;

use quark\entity\Entity;
use quark\lang\Language;
use quark\lang\Translatable;
use quark\permission\PermissibleBase;
use quark\permission\PermissibleDelegateTrait;
use quark\player\Player;
use quark\Server;
use quark\utils\TextFormat;

final class TestCommandSender implements CommandSender{
	use PermissibleDelegateTrait;

	private static ?Language $language = null;

	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	public array $messages = [];

	/**
	 * @phpstan-param array<string, bool> $permissions
	 */
	public function __construct(
		private Server $server,
		array $permissions
	){
		$this->perm = new PermissibleBase($permissions);
	}

	public static function language() : Language{
		return self::$language ??= new Language(Language::FALLBACK_LANGUAGE);
	}

	/**
	 * @phpstan-template T of Player
	 * @phpstan-param T $player
	 * @phpstan-return T
	 */
	public static function closedPlayer(Player $player) : Player{
		//doubles skip the constructor, so the destructor must not try to dispose them
		new \ReflectionProperty(Entity::class, "closed")->setValue($player, true);
		new \ReflectionProperty(Player::class, "logger")->setValue($player, new class extends \SimpleLogger{
			public function log($level, $message){

			}
		});
		return $player;
	}

	public function getLanguage() : Language{
		return self::language();
	}

	public function sendMessage(Translatable|string $message) : void{
		$this->messages[] = TextFormat::clean($message instanceof Translatable ? self::language()->translate($message) : $message);
	}

	public function getServer() : Server{
		return $this->server;
	}

	public function getName() : string{
		return "tester";
	}

	public function getScreenLineHeight() : int{
		return 7;
	}

	public function setScreenLineHeight(?int $height) : void{

	}
}
