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

namespace quark\command\defaults;

use quark\command\TestCommandSender;
use quark\lang\Translatable;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\ServerConfigGroup;
use quark\ServerProperties;
use quark\utils\Config;
use quark\utils\TextFormat;
use function array_keys;
use function bin2hex;
use function count;
use function file_exists;
use function random_bytes;
use function strtolower;
use function sys_get_temp_dir;
use function unlink;

final class WhitelistCommandTest extends DefaultCommandTestCase{
	private string $path;
	private ServerConfigGroup $config;
	/** @phpstan-var array<string, true> */
	private array $whitelist = [];
	private int $reloads = 0;
	/** @phpstan-var array<string, list<string>> */
	private array $kicks = [];

	protected function setUp() : void{
		parent::setUp();
		$this->path = sys_get_temp_dir() . "/quark-whitelist-" . bin2hex(random_bytes(6)) . ".properties";
		$file = new Config($this->path, Config::PROPERTIES);
		$this->config = new ServerConfigGroup($file, $file);

		$this->server->method("getConfigGroup")->willReturn($this->config);
		$this->server->method("hasWhitelist")->willReturnCallback(fn() : bool => $this->config->getConfigBool(ServerProperties::WHITELIST));
		$this->server->method("isWhitelisted")->willReturnCallback(fn(string $name) : bool => !$this->config->getConfigBool(ServerProperties::WHITELIST) || isset($this->whitelist[strtolower($name)]));
		$this->server->method("addWhitelist")->willReturnCallback(function(string $name) : void{
			$this->whitelist[strtolower($name)] = true;
		});
		$this->server->method("removeWhitelist")->willReturnCallback(function(string $name) : void{
			unset($this->whitelist[strtolower($name)]);
		});

		$list = self::createStub(Config::class);
		$list->method("reload")->willReturnCallback(function() : void{
			++$this->reloads;
		});
		$list->method("getAll")->willReturnCallback(fn() : array => array_keys($this->whitelist));
		$this->server->method("getWhitelisted")->willReturn($list);
	}

	protected function tearDown() : void{
		if(file_exists($this->path)){
			unlink($this->path);
		}
		parent::tearDown();
	}

	/**
	 * @param string[]|bool $permissions
	 * @phpstan-param list<string>|bool $permissions
	 */
	private function online(string $name, array|bool $permissions = true) : Player{
		$player = $this->player($name, $permissions);
		$player->method("kick")->willReturnCallback(function(Translatable|string $reason) use ($name) : bool{
			$this->kicks[$name][] = TextFormat::clean($reason instanceof Translatable ? TestCommandSender::language()->translate($reason) : $reason);
			return true;
		});
		return $player;
	}

	public function testAddAndRemoveAreCaseInsensitiveWords() : void{
		$console = $this->console();

		self::assertSame(["Added Steve to the whitelist"], $this->dispatch("whitelist add Steve", $console));
		self::assertSame(["Added Alex to the whitelist"], $this->dispatch("whitelist ADD Alex", $console));
		self::assertSame(["steve" => true, "alex" => true], $this->whitelist);

		self::assertSame(["Removed Steve from the whitelist"], $this->dispatch("whitelist Remove Steve", $console));
		self::assertSame(["alex" => true], $this->whitelist);
	}

	public function testRemoveKicksOnlineNonWhitelistedPlayer() : void{
		$this->online("Steve");
		$this->online("Alex");
		$console = $this->console();
		$this->dispatch("whitelist add Steve", $console);
		$this->dispatch("whitelist add Alex", $console);
		$this->dispatch("whitelist on", $console);
		$this->kicks = [];

		$this->dispatch("whitelist remove Steve", $console);
		self::assertSame(["Steve" => ["Kicked by admin. Reason: Server is whitelisted"]], $this->kicks);
	}

	public function testRemoveDoesNotKickWhileWhitelistIsOff() : void{
		$this->online("Steve");
		$this->dispatch("whitelist remove Steve", $this->console());
		self::assertSame([], $this->kicks);
	}

	public function testOnKicksEveryoneNotWhitelistedAndOffKeepsTheList() : void{
		$this->online("Steve");
		$this->online("Alex");
		$console = $this->console();
		$this->dispatch("whitelist add Steve", $console);

		self::assertSame(["Turned on the whitelist"], $this->dispatch("whitelist on", $console));
		self::assertTrue($this->config->getConfigBool(ServerProperties::WHITELIST));
		self::assertSame(["Alex" => ["Kicked by admin. Reason: Server is whitelisted"]], $this->kicks);

		self::assertSame(["Turned off the whitelist"], $this->dispatch("whitelist off", $console));
		self::assertFalse($this->config->getConfigBool(ServerProperties::WHITELIST));
		self::assertSame(["steve" => true], $this->whitelist);
	}

	public function testReloadKicksOnlyWhenEnabled() : void{
		$this->online("Steve");
		$console = $this->console();

		self::assertSame(["Reloaded the whitelist"], $this->dispatch("whitelist reload", $console));
		self::assertSame(1, $this->reloads);
		self::assertSame([], $this->kicks);

		$this->config->setConfigBool(ServerProperties::WHITELIST, true);
		$this->dispatch("whitelist reload", $console);
		self::assertSame(2, $this->reloads);
		self::assertSame(["Steve" => ["Kicked by admin. Reason: Server is whitelisted"]], $this->kicks);
	}

	public function testListIsSorted() : void{
		$console = $this->console();
		self::assertSame(["There are 0 (out of 0 seen) whitelisted players:", ""], $this->dispatch("whitelist list", $console));

		$this->dispatch("whitelist add Zed", $console);
		$this->dispatch("whitelist add Alex", $console);
		self::assertSame(["There are 2 (out of 2 seen) whitelisted players:", "alex, zed"], $this->dispatch("whitelist list", $console));
	}

	public function testInvalidInputChangesNothing() : void{
		$console = $this->console();

		foreach(["whitelist add", "whitelist remove", "whitelist add Steve Alex", "whitelist on now", "whitelist off 1", "whitelist list all", "whitelist reload x"] as $line){
			self::assertStringStartsWith("Usage: /whitelist ", $this->dispatch($line, $console)[0], $line);
		}
		foreach(["whitelist add bad!name", "whitelist remove rcon"] as $line){
			self::assertStringContainsString("Invalid value for <player>", $this->dispatch($line, $console)[0], $line);
		}
		foreach(["whitelist", "whitelist enable", "whitelist Steve"] as $line){
			self::assertStringStartsWith("Usage: /whitelist ", $this->dispatch($line, $console)[0], $line);
		}
		self::assertSame([], $this->whitelist);
		self::assertFalse($this->config->getConfigBool(ServerProperties::WHITELIST));
		self::assertSame(0, $this->reloads);
		self::assertSame([], $this->kicks);
	}

	public function testEachSubcommandHasItsOwnPermission() : void{
		$perms = [
			"reload" => DefaultPermissionNames::COMMAND_WHITELIST_RELOAD,
			"on" => DefaultPermissionNames::COMMAND_WHITELIST_ENABLE,
			"off" => DefaultPermissionNames::COMMAND_WHITELIST_DISABLE,
			"list" => DefaultPermissionNames::COMMAND_WHITELIST_LIST,
			"add Steve" => DefaultPermissionNames::COMMAND_WHITELIST_ADD,
			"remove Steve" => DefaultPermissionNames::COMMAND_WHITELIST_REMOVE
		];
		self::assertStringContainsString("permission", $this->dispatch("whitelist list", $this->player("None", []))[0]);

		foreach($perms as $line => $permission){
			$only = $this->player("Only" . count($this->players), [$permission]);
			foreach($perms as $other => $_){
				$messages = $this->dispatch("whitelist $other", $only);
				if($other === $line){
					self::assertStringNotContainsString("permission", $messages[0] ?? "", "$other with $permission");
				}else{
					self::assertStringContainsString("permission", $messages[0], "$other with only $permission");
				}
			}
		}
	}

	public function testUsageOnlyListsPermittedSyntaxes() : void{
		$player = $this->player("Lister", [DefaultPermissionNames::COMMAND_WHITELIST_LIST]);
		self::assertSame(["Usage: /whitelist list"], $this->dispatch("whitelist", $player));
	}
}
