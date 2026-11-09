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
use quark\network\mcpe\NetworkSession;
use quark\network\Network;
use quark\permission\BanList;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\utils\TextFormat;
use function bin2hex;
use function file_exists;
use function glob;
use function mkdir;
use function random_bytes;
use function rmdir;
use function sys_get_temp_dir;
use function unlink;

final class BanCommandsTest extends DefaultCommandTestCase{
	private string $dir;
	private BanList $nameBans;
	private BanList $ipBans;
	/** @phpstan-var array<string, list<string>> */
	private array $kicks = [];
	/** @phpstan-var list<string> */
	private array $blocked = [];
	/** @phpstan-var list<string> */
	private array $unblocked = [];

	protected function setUp() : void{
		parent::setUp();
		$this->dir = sys_get_temp_dir() . "/quark-bans-" . bin2hex(random_bytes(6));
		mkdir($this->dir);
		$this->nameBans = new BanList($this->dir . "/banned-players.txt");
		$this->ipBans = new BanList($this->dir . "/banned-ips.txt");
		$this->server->method("getNameBans")->willReturn($this->nameBans);
		$this->server->method("getIPBans")->willReturn($this->ipBans);

		$network = self::createStub(Network::class);
		$network->method("blockAddress")->willReturnCallback(function(string $address) : void{
			$this->blocked[] = $address;
		});
		$network->method("unblockAddress")->willReturnCallback(function(string $address) : void{
			$this->unblocked[] = $address;
		});
		$this->server->method("getNetwork")->willReturn($network);
	}

	protected function tearDown() : void{
		foreach(glob($this->dir . "/*") ?: [] as $file){
			unlink($file);
		}
		if(file_exists($this->dir)){
			rmdir($this->dir);
		}
		parent::tearDown();
	}

	/**
	 * @param string[]|bool $permissions
	 * @phpstan-param list<string>|bool $permissions
	 */
	private function online(string $name, string $ip = "10.0.0.1", array|bool $permissions = true) : Player{
		$player = $this->player($name, $permissions);
		$player->method("kick")->willReturnCallback(function(Translatable|string $reason) use ($name) : bool{
			$this->kicks[$name][] = TextFormat::clean($reason instanceof Translatable ? TestCommandSender::language()->translate($reason) : $reason);
			return true;
		});
		$session = self::createStub(NetworkSession::class);
		$session->method("getIp")->willReturn($ip);
		$player->method("getNetworkSession")->willReturn($session);
		return $player;
	}

	public function testBanStoresReasonVerbatimAndKicksOnlinePlayer() : void{
		$this->online("Steve");
		$console = $this->console();

		self::assertSame(["Banned player Steve"], $this->dispatch("ban steve  \"quoted\"   and  spaced ", $console));
		self::assertSame('"quoted"   and  spaced', $this->nameBans->getEntry("steve")?->getReason());
		self::assertSame(["Steve" => ['You are banned from this server. Reason: "quoted"   and  spaced']], $this->kicks);
	}

	public function testBanWithoutReasonAndOfflineName() : void{
		$steve = $this->online("Steve");

		self::assertSame(["Banned player Steve"], $this->dispatch("ban Steve", $this->console()));
		self::assertSame(["Steve" => ["You are banned from this server"]], $this->kicks);

		self::assertSame(["Banned player Ghost"], $this->dispatch("ban Ghost being a ghost", $steve));
		self::assertSame("being a ghost", $this->nameBans->getEntry("ghost")?->getReason());
		self::assertSame("Steve", $this->nameBans->getEntry("ghost")?->getSource());
	}

	public function testBanRequiresAName() : void{
		$console = $this->console();
		self::assertSame(["Usage: /ban <player> [reason...]"], $this->dispatch("ban", $console));
		self::assertSame([], $this->nameBans->getEntries());
	}

	public function testBanListShowsSortedEntries() : void{
		$console = $this->console();
		self::assertSame(["There are 0 total banned players:", ""], $this->dispatch("banlist", $console));

		$this->dispatch("ban Zed", $console);
		$this->dispatch("ban Alex", $console);
		$this->dispatch("ban-ip 1.2.3.4", $console);

		self::assertSame(["There are 2 total banned players:", "alex, zed"], $this->dispatch("banlist", $console));
		self::assertSame(["There are 2 total banned players:", "alex, zed"], $this->dispatch("banlist Players", $console));
		self::assertSame(["There are 1 total banned IP addresses:", "1.2.3.4"], $this->dispatch("banlist IPS", $console));
	}

	public function testBanListRejectsUnknownTypeAndTrailingArguments() : void{
		$console = $this->console();
		self::assertStringContainsString("\"names\" is not valid", $this->dispatch("banlist names", $console)[0]);
		self::assertSame(["Usage: /banlist [type]"], $this->dispatch("banlist ips players", $console));
	}

	public function testBanIpByAddressKicksEveryPlayerOnIt() : void{
		$this->online("Steve", "5.6.7.8");
		$this->online("Alex", "5.6.7.8");
		$this->online("Bob", "9.9.9.9");

		self::assertSame(["Banned IP address 5.6.7.8"], $this->dispatch("ban-ip 5.6.7.8 spamming here", $this->console()));
		self::assertSame("spamming here", $this->ipBans->getEntry("5.6.7.8")?->getReason());
		self::assertSame(["Steve" => ["You are banned from this server. Reason: spamming here"], "Alex" => ["You are banned from this server. Reason: spamming here"]], $this->kicks);
		self::assertSame(["5.6.7.8"], $this->blocked);
	}

	public function testBanIpByPlayerPrefixUsesTheirAddress() : void{
		$this->online("Steve", "5.6.7.8");

		self::assertSame(["Banned IP address 5.6.7.8 belonging to Steve"], $this->dispatch("ban-ip ste", $this->console()));
		self::assertTrue($this->ipBans->isBanned("5.6.7.8"));
		self::assertSame(["Steve" => ["You are banned from this server. Reason: IP banned"]], $this->kicks);
	}

	public function testBanIpAcceptsIpv6() : void{
		self::assertSame(["Banned IP address ::1"], $this->dispatch("ban-ip ::1", $this->console()));
		self::assertSame(["::1"], $this->blocked);
	}

	public function testBanIpRejectsInvalidTargets() : void{
		$this->online("Steve");
		$console = $this->console();

		foreach(["ban-ip 1.2.3", "ban-ip 999.1.1.1", "ban-ip Nobody", "ban-ip @a"] as $line){
			$messages = $this->dispatch($line, $console);
			self::assertStringContainsString("Invalid value for <target>", $messages[0], $line);
		}
		self::assertSame(["Usage: /ban-ip <target> [reason...]"], $this->dispatch("ban-ip", $console));
		self::assertSame([], $this->ipBans->getEntries());
		self::assertSame([], $this->blocked);
		self::assertSame([], $this->kicks);
	}

	public function testPardonRemovesBansWithAliases() : void{
		$console = $this->console();
		$this->dispatch("ban Steve", $console);
		$this->dispatch("ban Alex", $console);

		self::assertSame(["Unbanned player Steve"], $this->dispatch("pardon Steve", $console));
		self::assertSame(["Unbanned player Alex"], $this->dispatch("unban Alex", $console));
		self::assertSame([], $this->nameBans->getEntries());
	}

	public function testPardonRequiresExactlyOneName() : void{
		$console = $this->console();
		$this->dispatch("ban Steve", $console);

		self::assertSame(["Usage: /pardon <player>"], $this->dispatch("pardon", $console));
		self::assertSame(["Usage: /pardon <player>"], $this->dispatch("pardon Steve Alex", $console));
		self::assertTrue($this->nameBans->isBanned("Steve"));
	}

	public function testPardonIpUnblocksAddress() : void{
		$console = $this->console();
		$this->dispatch("ban-ip 1.2.3.4", $console);

		self::assertSame(["Unbanned IP address 1.2.3.4"], $this->dispatch("pardon-ip 1.2.3.4", $console));
		self::assertSame([], $this->ipBans->getEntries());
		self::assertSame(["1.2.3.4"], $this->unblocked);

		$this->dispatch("ban-ip ::1", $console);
		self::assertSame(["Unbanned IP address ::1"], $this->dispatch("unban-ip ::1", $console));
	}

	public function testPardonIpRejectsInvalidAddressesWithoutChanges() : void{
		$console = $this->console();
		$this->dispatch("ban-ip 1.2.3.4", $console);

		foreach(["pardon-ip Steve", "pardon-ip 1.2.3", "pardon-ip 1.2.3.4.5"] as $line){
			self::assertStringContainsString("Invalid value for <address>", $this->dispatch($line, $console)[0], $line);
		}
		self::assertSame(["Usage: /pardon-ip <address>"], $this->dispatch("pardon-ip", $console));
		self::assertSame(["Usage: /pardon-ip <address>"], $this->dispatch("pardon-ip 1.2.3.4 1.2.3.5", $console));
		self::assertTrue($this->ipBans->isBanned("1.2.3.4"));
		self::assertSame([], $this->unblocked);
	}

	public function testKickMatchesPrefixAndKeepsReasonVerbatim() : void{
		$this->online("Steve");
		$console = $this->console();

		self::assertSame(["Kicked Steve from the game: 'be \"nice\"  please'"], $this->dispatch("kick ste be \"nice\"  please  ", $console));
		self::assertSame(["Steve" => ['Kicked by admin. Reason: be "nice"  please']], $this->kicks);
	}

	public function testKickWithoutReason() : void{
		$this->online("Steve");
		self::assertSame(["Kicked Steve from the game"], $this->dispatch("kick Steve", $this->console()));
		self::assertSame(["Steve" => ["Kicked by admin"]], $this->kicks);
	}

	public function testKickRejectsUnknownPlayerAndMissingArguments() : void{
		$this->online("Steve");
		$console = $this->console();

		self::assertStringContainsString("Cannot find a player matching \"Nobody\"", $this->dispatch("kick Nobody", $console)[0]);
		self::assertSame(["Usage: /kick <player> [reason...]"], $this->dispatch("kick", $console));
		self::assertSame([], $this->kicks);
	}

	public function testCommandsRequirePermission() : void{
		$alex = $this->online("Alex", permissions: []);
		$this->online("Steve");

		foreach(["ban Steve", "ban-ip 1.2.3.4", "banlist", "pardon Steve", "pardon-ip 1.2.3.4", "kick Steve"] as $line){
			self::assertStringContainsString("permission", $this->dispatch($line, $alex)[0], $line);
		}
		self::assertSame([], $this->nameBans->getEntries());
		self::assertSame([], $this->ipBans->getEntries());
		self::assertSame([], $this->kicks);

		$moderator = $this->online("Mod", permissions: [DefaultPermissionNames::COMMAND_KICK]);
		self::assertSame(["Kicked Steve from the game"], $this->dispatch("kick Steve", $moderator));
		self::assertStringContainsString("permission", $this->dispatch("ban Alex", $moderator)[0]);
		self::assertFalse($this->nameBans->isBanned("Alex"));
	}
}
