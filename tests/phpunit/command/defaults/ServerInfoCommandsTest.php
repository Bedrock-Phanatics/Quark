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

use PHPUnit\Framework\MockObject\Stub;
use quark\network\BidirectionalBandwidthStatsTracker;
use quark\network\Network;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\plugin\Plugin;
use quark\plugin\PluginDescription;
use quark\plugin\PluginManager;
use quark\world\Position;
use quark\world\World;
use quark\world\WorldManager;
use function implode;

final class ServerInfoCommandsTest extends DefaultCommandTestCase{
	/** @phpstan-var array<string, Plugin> */
	private array $plugins = [];

	protected function setUp() : void{
		parent::setUp();
		$this->server->method("getMaxPlayers")->willReturn(20);

		$manager = self::createStub(PluginManager::class);
		$manager->method("getPlugins")->willReturnCallback(fn() : array => $this->plugins);
		$manager->method("getPlugin")->willReturnCallback(fn(string $name) : ?Plugin => $this->plugins[$name] ?? null);
		$this->server->method("getPluginManager")->willReturn($manager);
	}

	/**
	 * @param string[] $authors
	 * @phpstan-param list<string> $authors
	 */
	private function addPlugin(string $name, string $version, bool $enabled = true, string $description = "", array $authors = [], string $website = "") : void{
		$info = self::createStub(PluginDescription::class);
		$info->method("getName")->willReturn($name);
		$info->method("getVersion")->willReturn($version);
		$info->method("getFullName")->willReturn("$name v$version");
		$info->method("getDescription")->willReturn($description);
		$info->method("getAuthors")->willReturn($authors);
		$info->method("getWebsite")->willReturn($website);

		$plugin = self::createStub(Plugin::class);
		$plugin->method("getName")->willReturn($name);
		$plugin->method("isEnabled")->willReturn($enabled);
		$plugin->method("getDescription")->willReturn($info);
		$this->plugins[$name] = $plugin;
	}

	private function visible(Player&Stub $player, bool ...$sees) : void{
		$player->method("canSee")->willReturnCallback(fn(Player $other) : bool => $sees[$other->getName()] ?? true);
	}

	public function testListShowsSortedNamesAndHidesVanishedPlayersFromPlayers() : void{
		$steve = $this->player("Steve");
		$this->player("Alex");
		$this->player("Ghost");
		$this->visible($steve, Ghost: false);

		self::assertSame(["There are 3/20 players online:", "Alex, Ghost, Steve"], $this->dispatch("list", $this->console()));
		self::assertSame(["There are 2/20 players online:", "Alex, Steve"], $this->dispatch("list", $steve));
	}

	public function testListWithNobodyOnline() : void{
		self::assertSame(["There are 0/20 players online:", ""], $this->dispatch("list", $this->console()));
	}

	public function testPluginsListsSortedWithAlias() : void{
		self::assertSame(["Plugins (0): "], $this->dispatch("plugins", $this->console()));

		$this->addPlugin("Zeta", "1.0");
		$this->addPlugin("Alpha", "2.1");
		self::assertSame(["Plugins (2): Alpha v2.1, Zeta v1.0"], $this->dispatch("pl", $this->console()));
	}

	public function testSeedUsesSendersWorldOrTheDefaultWorld() : void{
		$default = self::createStub(World::class);
		$default->method("getSeed")->willReturn(111);
		$manager = self::createStub(WorldManager::class);
		$manager->method("getDefaultWorld")->willReturn($default);
		$this->server->method("getWorldManager")->willReturn($manager);

		$other = self::createStub(World::class);
		$other->method("getSeed")->willReturn(222);
		$other->method("isLoaded")->willReturn(true);
		$steve = $this->player("Steve");
		$steve->method("getPosition")->willReturn(new Position(0, 64, 0, $other));

		self::assertSame(["Seed: 111"], $this->dispatch("seed", $this->console()));
		self::assertSame(["Seed: 222"], $this->dispatch("seed", $steve));
	}

	public function testVersionWithoutArgumentsShowsServerInformation() : void{
		$output = implode("\n", $this->dispatch("version", $this->console()));
		self::assertStringContainsString("Quark", $output);
		self::assertStringContainsString("PHP", $output);
		self::assertSame($output, implode("\n", $this->dispatch("about", $this->console())));
		self::assertSame($output, implode("\n", $this->dispatch("ver", $this->console())));
	}

	public function testVersionDescribesExactAndPartialPluginMatches() : void{
		$this->addPlugin("Economy", "1.2", description: "Money", authors: ["Steve"], website: "https://example.com");
		$this->addPlugin("EconomyShop", "3.0", authors: ["Alex", "Bob"]);
		$console = $this->console();

		$exact = $this->dispatch("version Economy", $console);
		self::assertCount(1, $exact);
		self::assertStringContainsString("Economy", $exact[0]);
		self::assertStringContainsString("1.2", $exact[0]);
		self::assertStringContainsString("Money", $exact[0]);
		self::assertStringContainsString("https://example.com", $exact[0]);
		self::assertStringNotContainsString("EconomyShop", $exact[0]);

		$partial = $this->dispatch("version shop", $console);
		self::assertCount(1, $partial);
		self::assertStringContainsString("EconomyShop", $partial[0]);
		self::assertStringContainsString("Alex, Bob", $partial[0]);

		self::assertCount(2, $this->dispatch("version eco", $console));
	}

	public function testVersionOfUnknownPluginAndMultiWordInput() : void{
		$this->addPlugin("Economy", "1.2");
		$console = $this->console();

		$unknown = ["This server is not running any plugin by that name. Use /plugins to get a list of plugins."];
		self::assertSame($unknown, $this->dispatch("version Nope", $console));
		self::assertSame($unknown, $this->dispatch("version Economy 1.2", $console));
	}

	public function testStatusShowsReportAndRejectsArguments() : void{
		$network = self::createStub(Network::class);
		$network->method("getBandwidthTracker")->willReturn(new BidirectionalBandwidthStatsTracker(5));
		$this->server->method("getNetwork")->willReturn($network);

		$console = $this->console();
		$messages = $this->dispatch("status", $console);
		self::assertGreaterThan(5, $messages);
		self::assertStringContainsString("Server status", $messages[0]);
		self::assertSame(["Usage: /status"], $this->dispatch("status now", $console));
	}

	public function testCommandsRequirePermissionAndRejectTrailingArguments() : void{
		$denied = $this->player("Denied", []);
		foreach(["list", "plugins", "seed", "version", "status"] as $name){
			self::assertStringContainsString("permission", $this->dispatch($name, $denied)[0], $name);
		}

		$console = $this->console();
		self::assertSame(["Usage: /list"], $this->dispatch("list all", $console));
		self::assertSame(["Usage: /plugins"], $this->dispatch("plugins all", $console));
		self::assertSame(["Usage: /seed"], $this->dispatch("seed 1", $console));

		$lister = $this->player("Lister", [DefaultPermissionNames::COMMAND_LIST]);
		$this->visible($lister);
		self::assertSame(["There are 2/20 players online:", "Denied, Lister"], $this->dispatch("list", $lister));
	}
}
