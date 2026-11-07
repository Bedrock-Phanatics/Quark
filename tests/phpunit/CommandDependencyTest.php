<?php

declare(strict_types=1);

namespace quark;

use PHPUnit\Framework\TestCase;
use quark\command\Command;
use quark\command\CommandMap;
use quark\command\CommandSender;
use quark\command\defaults\StopCommand;
use quark\command\FormattedCommandAlias;
use quark\command\SimpleCommandMap;
use quark\console\ConsoleCommandSender;
use quark\lang\Language;
use quark\lang\Translatable;
use quark\permission\DefaultPermissions;
use quark\utils\BroadcastLoggerForwarder;

final class CommandDependencyTest extends TestCase{
	protected function setUp() : void{
		\quark\pulse\internal\PulseZones::init();
	}

	public function testConsoleAndForwarderWorkWithoutServer() : void{
		DefaultPermissions::registerCorePermissions();
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
		$map = new SimpleCommandMap(self::createStub(Server::class));
		self::assertInstanceOf(StopCommand::class, $map->getCommand("stop"));
		self::assertNotNull($map->getCommand("pulse"));
	}
}
