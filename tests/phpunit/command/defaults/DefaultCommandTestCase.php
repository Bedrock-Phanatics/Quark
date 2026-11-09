<?php

declare(strict_types=1);

namespace quark\command\defaults;

use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use quark\command\SimpleCommandMap;
use quark\command\TestCommandSender;
use quark\lang\Translatable;
use quark\permission\DefaultPermissions;
use quark\permission\Permission;
use quark\player\Player;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;
use quark\Server;
use quark\utils\TextFormat;
use function in_array;
use function is_bool;
use function str_starts_with;
use function strtolower;

/**
 * Dispatches command lines through a real command map with every built-in command registered.
 */
abstract class DefaultCommandTestCase extends TestCase{
	protected Server&Stub $server;
	protected SimpleCommandMap $map;
	/** @phpstan-var array<string, Player&Stub> */
	protected array $players = [];
	/** @phpstan-var array<string, list<string>> */
	protected array $sent = [];

	protected function setUp() : void{
		Pulse::reset();
		PulseZones::init();
		DefaultPermissions::registerCorePermissions();
		$this->server = self::createStub(Server::class);
		$this->server->method("getPlayerExact")->willReturnCallback(fn(string $name) : ?Player => $this->players[strtolower($name)] ?? null);
		$this->server->method("getPlayerByPrefix")->willReturnCallback(function(string $name) : ?Player{
			foreach($this->players as $player){
				if(str_starts_with(strtolower($player->getName()), strtolower($name))){
					return $player;
				}
			}
			return null;
		});
		$this->server->method("getOnlinePlayers")->willReturnCallback(fn() : array => $this->players);
		$this->map = new SimpleCommandMap($this->server);
		$this->server->method("getCommandMap")->willReturn($this->map);
	}

	protected function tearDown() : void{
		Pulse::reset();
	}

	/**
	 * @phpstan-param array<string, bool> $permissions
	 */
	protected function console(array $permissions = [DefaultPermissions::ROOT_CONSOLE => true]) : TestCommandSender{
		return new TestCommandSender($this->server, $permissions);
	}

	/**
	 * @param string[]|bool $permissions every permission, none, or exactly the listed names
	 * @phpstan-param list<string>|bool $permissions
	 * @return Player&Stub
	 */
	protected function player(string $name, array|bool $permissions = true) : Player{
		$player = TestCommandSender::closedPlayer(self::createStub(Player::class));
		$player->method("getName")->willReturn($name);
		$player->method("getDisplayName")->willReturn($name);
		$player->method("getServer")->willReturn($this->server);
		$player->method("getLanguage")->willReturn(TestCommandSender::language());
		$player->method("hasPermission")->willReturnCallback(
			fn(Permission|string $permission) : bool => is_bool($permissions) ? $permissions : in_array($permission instanceof Permission ? $permission->getName() : $permission, $permissions, true)
		);
		$player->method("sendMessage")->willReturnCallback(function(Translatable|string $message) use ($name) : void{
			$this->sent[$name][] = TextFormat::clean($message instanceof Translatable ? TestCommandSender::language()->translate($message) : $message);
		});
		return $this->players[strtolower($name)] = $player;
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	protected function dispatch(string $commandLine, TestCommandSender|Player $sender) : array{
		if($sender instanceof TestCommandSender){
			$sender->messages = [];
		}else{
			$this->sent[$sender->getName()] = [];
		}
		self::assertTrue($this->map->dispatch($sender, $commandLine));
		return $sender instanceof TestCommandSender ? $sender->messages : $this->sent[$sender->getName()];
	}
}
