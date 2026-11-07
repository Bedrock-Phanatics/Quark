<?php

declare(strict_types=1);

namespace quark;

use PHPUnit\Framework\TestCase;
use quark\command\Command;
use quark\command\CommandMap;
use quark\command\CommandSender;
use quark\command\defaults\DefaultGamemodeCommand;
use quark\command\defaults\HelpCommand;
use quark\command\defaults\PluginsCommand;
use quark\command\defaults\SeedCommand;
use quark\command\defaults\StopCommand;
use quark\command\defaults\VersionCommand;
use quark\command\FormattedCommandAlias;
use quark\command\SimpleCommandMap;
use quark\console\ConsoleCommandSender;
use quark\lang\Language;
use quark\lang\Translatable;
use quark\permission\DefaultPermissions;
use quark\plugin\PluginManager;
use quark\utils\BroadcastLoggerForwarder;
use quark\utils\Config;
use quark\world\World;
use quark\world\WorldManager;

final class CommandDependencyTest extends TestCase{
	protected function setUp() : void{
		\quark\pulse\internal\PulseZones::init();
		DefaultPermissions::registerCorePermissions();
	}

	public function testConsoleAndForwarderWorkWithoutServer() : void{
		$logger = $this->createMock(\Logger::class);
		$logger->expects(self::exactly(2))->method("info")->with("response");
		$language = $this->createMock(Language::class);
		$language->expects(self::once())->method("translate")->willReturn("response");
		$console = new ConsoleCommandSender($logger, $language);
		$console->sendMessage("  response\n");
		(new BroadcastLoggerForwarder($logger, $language))->sendMessage(new Translatable("test"));
	}

	public function testCommandUsesInjectedServerWithServerlessSender() : void{
		$server = $this->createMock(Server::class);
		$server->expects(self::once())->method("shutdown");
		$server->expects(self::once())->method("getBroadcastChannelSubscribers")->with(Server::BROADCAST_CHANNEL_ADMINISTRATIVE)->willReturn([]);
		$sender = $this->createMock(CommandSender::class);
		$sender->expects(self::once())->method("sendMessage");
		(new StopCommand($server))->execute($sender, "stop", []);
		self::assertFalse(method_exists($sender, "getServer"));
	}

	public function testBroadcastExcludesSourceAndCanSuppressDirectReply() : void{
		$source = $this->createMock(CommandSender::class);
		$source->expects(self::never())->method("sendMessage");
		$recipient = $this->createMock(CommandSender::class);
		$recipient->expects(self::once())->method("sendMessage")->with(self::isInstanceOf(Translatable::class));
		$server = self::createStub(Server::class);
		$server->method("getBroadcastChannelSubscribers")->willReturn([$source, $recipient]);
		Command::broadcastCommandMessage($server, $source, "message", false);
	}

	public function testAliasUsesInjectedCommandMap() : void{
		$sender = self::createStub(CommandSender::class);
		$target = $this->createMock(Command::class);
		$target->method("getPulseZone")->willReturn(\quark\pulse\Pulse::zone("test.alias"));
		$target->expects(self::once())->method("execute")->with($sender, "target", ["argument"]);
		$map = $this->createMock(CommandMap::class);
		$map->expects(self::once())->method("getCommand")->with("target")->willReturn($target);
		$alias = new FormattedCommandAlias("alias", ['target $1'], $map);
		self::assertTrue($alias->execute($sender, "alias", ["argument"]));
	}

	public function testDefaultCommandsAreConstructedWithDependencies() : void{
		$server = self::createStub(Server::class);
		$config = new ServerConfigGroup(self::createStub(Config::class), self::createStub(Config::class));
		$server->method("getConfigGroup")->willReturn($config);
		$map = new SimpleCommandMap($server);
		self::assertInstanceOf(StopCommand::class, $map->getCommand("stop"));
		self::assertNotNull($map->getCommand("pulse"));
		$help = $map->getCommand("help");
		$map->clearCommands();
		self::assertNotSame($help, $map->getCommand("help"));
		$sender = $this->createMock(CommandSender::class);
		$sender->method("hasPermission")->willReturn(true);
		$sender->method("getLanguage")->willReturn(self::createStub(Language::class));
		$sender->expects(self::exactly(4))->method("sendMessage");
		self::assertInstanceOf(HelpCommand::class, $help);
		$help->execute($sender, "help", ["stop"]);
	}

	public function testHelpUsesInjectedMapForListingAndLookup() : void{
		$map = $this->createMock(CommandMap::class);
		$map->expects(self::once())->method("getCommands")->willReturn([]);
		$map->expects(self::once())->method("getCommand")->with("missing")->willReturn(null);
		$sender = $this->createMock(CommandSender::class);
		$sender->method("getScreenLineHeight")->willReturn(10);
		$sender->method("getLanguage")->willReturn(self::createStub(Language::class));
		$sender->expects(self::exactly(2))->method("sendMessage");
		$command = new HelpCommand($map);
		$command->execute($sender, "help", []);
		$command->execute($sender, "help", ["missing"]);
	}

	public function testPluginCommandsUseInjectedManager() : void{
		$plugins = $this->createMock(PluginManager::class);
		$plugins->expects(self::exactly(2))->method("getPlugins")->willReturn([]);
		$plugins->expects(self::once())->method("getPlugin")->with("missing")->willReturn(null);
		$sender = $this->createMock(CommandSender::class);
		$sender->expects(self::exactly(2))->method("sendMessage");
		(new PluginsCommand($plugins))->execute($sender, "plugins", []);
		(new VersionCommand($plugins))->execute($sender, "version", ["missing"]);
	}

	public function testSeedUsesCurrentDefaultWorld() : void{
		$first = self::createStub(World::class);
		$first->method("getSeed")->willReturn(123);
		$second = self::createStub(World::class);
		$second->method("getSeed")->willReturn(456);
		$worlds = $this->createMock(WorldManager::class);
		$worlds->expects(self::exactly(2))->method("getDefaultWorld")->willReturnOnConsecutiveCalls($first, $second);
		$seeds = [];
		$sender = self::createStub(CommandSender::class);
		$sender->method("sendMessage")->willReturnCallback(static function(Translatable|string $message) use (&$seeds) : void{
			self::assertInstanceOf(Translatable::class, $message);
			$seeds[] = $message->getParameter(0);
		});
		$command = new SeedCommand($worlds);
		$command->execute($sender, "seed", []);
		$command->execute($sender, "seed", []);
		self::assertSame(["123", "456"], $seeds);
	}

	public function testDefaultGamemodeUpdatesInjectedConfiguration() : void{
		$properties = $this->createMock(Config::class);
		$properties->expects(self::once())->method("set")->with(ServerProperties::GAME_MODE, "CREATIVE");
		$config = new ServerConfigGroup(self::createStub(Config::class), $properties);
		$sender = $this->createMock(CommandSender::class);
		$sender->expects(self::once())->method("sendMessage");
		(new DefaultGamemodeCommand($config))->execute($sender, "defaultgamemode", ["creative"]);
	}
}
