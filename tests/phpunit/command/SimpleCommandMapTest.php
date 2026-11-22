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

use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use quark\command\argument\IntegerArgument;
use quark\command\utils\InvalidCommandSyntaxException;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\server\CommandEvent;
use quark\permission\DefaultPermissionNames;
use quark\permission\DefaultPermissions;
use quark\permission\Permission;
use quark\permission\PermissionManager;
use quark\plugin\Plugin;
use quark\plugin\PluginDescription;
use quark\plugin\PluginManager;
use quark\plugin\PluginOwned;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;
use quark\scheduler\ClosureTask;
use quark\scheduler\TaskScheduler;
use quark\Server;
use quark\thread\log\AttachableThreadSafeLogger;
use function implode;

final class SimpleCommandMapTest extends TestCase{
	private Server&Stub $server;
	private SimpleCommandMap $map;
	/**
	 * @var mixed[][]
	 * @phpstan-var list<array{string, string, list<string>}>
	 */
	private array $calls = [];

	protected function setUp() : void{
		Pulse::reset();
		PulseZones::init();
		DefaultPermissions::registerCorePermissions();
		$manager = PermissionManager::getInstance();
		foreach(["test.root", "test.other", "test.admin"] as $name){
			if($manager->getPermission($name) === null){
				$manager->addPermission(new Permission($name));
			}
		}
		$this->server = self::createStub(Server::class);
		$this->server->method("getLogger")->willReturn(new class extends AttachableThreadSafeLogger{
			public function emergency($message){}
			public function alert($message){}
			public function critical($message){}
			public function error($message){}
			public function warning($message){}
			public function notice($message){}
			public function info($message){}
			public function debug($message){}
			public function log($level, $message){}
			public function logException(\Throwable $e, $trace = null){}
		});
		$this->map = new SimpleCommandMap();
		$this->server->method("getCommandMap")->willReturn($this->map);
	}

	protected function tearDown() : void{
		Pulse::reset();
	}

	/**
	 * @phpstan-param array<string, bool> $permissions
	 */
	private function sender(array $permissions = ["test.root" => true]) : TestCommandSender{
		return new TestCommandSender($this->server, $permissions);
	}

	/**
	 * @param string[] $aliases
	 * @phpstan-param list<string> $aliases
	 */
	private function legacy(string $name, string $permission = "test.root", array $aliases = [], mixed $result = true) : Command{
		$command = new class($name, "", null, $aliases, $this, $result) extends Command{
			/** @phpstan-param list<string> $aliases */
			public function __construct(string $name, string $description, ?string $usage, array $aliases, private SimpleCommandMapTest $test, private mixed $result){
				parent::__construct($name, $description, $usage, $aliases);
			}

			public function execute(CommandSender $sender, string $commandLabel, array $args){
				$this->test->recordLegacy($this->getName(), $commandLabel, $args);
				if($this->result instanceof \Throwable){
					throw $this->result;
				}
				return $this->result;
			}
		};
		$command->setPermission($permission);
		return $command;
	}

	/**
	 * @param string[] $args
	 * @phpstan-param list<string> $args
	 * @internal
	 */
	public function recordLegacy(string $name, string $label, array $args) : void{
		$this->calls[] = [$name, $label, $args];
	}

	private function plugin(bool &$enabled, ?\Throwable $onDisable = null, ?TaskScheduler $scheduler = null, string $name = "Owner") : Plugin{
		$plugin = self::createStub(Plugin::class);
		$plugin->method("isEnabled")->willReturnCallback(function() use (&$enabled) : bool{
			return $enabled;
		});
		$plugin->method("onEnableStateChange")->willReturnCallback(function(bool $state) use (&$enabled, $onDisable) : void{
			$enabled = $state;
			if(!$state && $onDisable !== null){
				throw $onDisable;
			}
		});
		$plugin->method("getDescription")->willReturn(new PluginDescription(["name" => $name, "version" => "1.0.0", "main" => "owner\\Main", "api" => ["6.0.0"]]));
		$plugin->method("getScheduler")->willReturn($scheduler ?? new TaskScheduler($name));
		return $plugin;
	}

	/**
	 * @phpstan-param (\Closure(CommandArgs) : void)|null $handler
	 */
	private function owned(Plugin $plugin, string $name, ?\Closure $handler = null) : Command{
		$command = new class($name, $plugin, $handler) extends Command implements PluginOwned{
			/**
			 * @phpstan-param (\Closure(CommandArgs) : void)|null $handler
			 */
			public function __construct(string $name, private Plugin $plugin, private ?\Closure $handler){
				parent::__construct($name);
			}

			protected function run(CommandSender $sender, CommandArgs $args) : void{
				if($this->handler !== null){
					($this->handler)($args);
				}
			}

			public function getOwningPlugin() : Plugin{
				return $this->plugin;
			}
		};
		$command->setPermission("test.root");
		return $command;
	}

	public function testLegacyDispatchKeepsRawTokensAndReturnSemantics() : void{
		$this->map->register("test", $this->legacy("legacy", aliases: ["lg"]));
		$sender = $this->sender();

		self::assertTrue($this->map->dispatch($sender, "legacy  a \"b c\" \"\" \\\"d"));
		self::assertTrue($this->map->dispatch($sender, "lg"));
		self::assertTrue($this->map->dispatch($sender, "test:legacy x"));
		self::assertSame([
			["legacy", "legacy", ["a", "b c", "\"d"]],
			["legacy", "lg", []],
			["legacy", "test:legacy", ["x"]],
		], $this->calls);

		self::assertFalse($this->map->dispatch($sender, "missing arg"));
		self::assertFalse($this->map->dispatch($sender, ""));
		self::assertSame([
			"Unknown command: missing. Use /help for a list of available commands.",
			"Unknown command: . Use /help for a list of available commands.",
		], $sender->messages);
	}

	public function testLegacyFallbackPrefixesAndAliasCollisionsAreUnchanged() : void{
		$first = $this->legacy("dup", aliases: ["shared"]);
		$second = $this->legacy("dup", aliases: ["shared", "own"]);
		self::assertTrue($this->map->register("one", $first));
		self::assertFalse($this->map->register("TWO", $second));
		self::assertSame("two:dup", $second->getLabel());
		self::assertSame(["own"], $second->getAliases());
		self::assertSame($first, $this->map->getCommand("dup"));
		self::assertSame($first, $this->map->getCommand("shared"));
		self::assertSame($second, $this->map->getCommand("two:dup"));
		self::assertSame($second, $this->map->getCommand("two:shared"));
		self::assertNull($this->map->getCommand("DUP"));
	}

	public function testLegacyPermissionsUsageAndExceptions() : void{
		$this->map->register("test", $this->legacy("anyof", "test.other;test.admin"));
		$this->map->register("test", $this->legacy("usage", result: new InvalidCommandSyntaxException()));
		$this->map->register("test", $this->legacy("broken", result: new \RuntimeException("legacy bug")));

		$sender = $this->sender(["test.root" => true, "test.admin" => true]);
		$this->map->dispatch($sender, "anyof");
		$this->map->dispatch($sender, "usage");
		self::assertSame(["Usage: /usage"], $sender->messages);
		self::assertCount(2, $this->calls);

		$console = $this->sender([DefaultPermissions::ROOT_CONSOLE => true]);
		$this->map->dispatch($console, "anyof");
		self::assertSame(["You don't have permission to use the command: anyof"], $console->messages);
		self::assertCount(2, $this->calls);

		$this->expectExceptionMessage("legacy bug");
		$this->map->dispatch($sender, "broken");
	}

	public function testDispatchParsedSkipsTokenization() : void{
		$this->map->register("test", $this->legacy("legacy"));
		self::assertTrue($this->map->dispatchParsed($this->sender(), "legacy", ["\"quoted\"", "a b"]));
		self::assertSame([["legacy", "legacy", ["\"quoted\"", "a b"]]], $this->calls);
		self::assertFalse($this->map->dispatchParsed($this->sender(), "legacy a", []));
	}

	public function testPluginCommandAndClosureCommandStillRoute() : void{
		$enabled = true;
		$plugin = $this->plugin($enabled);
		$results = [true, false];
		$executor = new class($results) implements CommandExecutor{
			public int $calls = 0;

			/** @param bool[] $results */
			public function __construct(private array $results){}

			public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
				return $this->results[$this->calls++] ?? true;
			}
		};
		$command = new PluginCommand("manifest", $plugin, $executor);
		$command->setPermission("test.root");
		$command->setUsage("/manifest <x>");
		$this->map->register("owner", $command);

		$sender = $this->sender();
		$this->map->dispatch($sender, "manifest");
		$this->map->dispatch($sender, "manifest");
		self::assertSame(["Usage: /manifest <x>"], $sender->messages);
		$enabled = false;
		$this->map->dispatch($sender, "manifest");
		self::assertSame(2, $executor->calls);

		$closure = new ClosureCommand("closure", function(CommandSender $sender, Command $command, string $commandLabel, array $args) : mixed{
			$this->recordLegacy("closure", $commandLabel, $args);
			return true;
		}, ["test.root"]);
		$this->map->register("test", $closure);
		$this->map->dispatch($sender, "closure 1");
		self::assertSame([["closure", "closure", ["1"]]], $this->calls);
	}

	public function testDisabledOwnersCannotExecuteAnyCommandKind() : void{
		$enabled = true;
		$plugin = $this->plugin($enabled);
		$legacy = new class("ownedlegacy", $plugin, $this) extends Command implements PluginOwned{
			public function __construct(string $name, private Plugin $plugin, private SimpleCommandMapTest $test){
				parent::__construct($name);
			}

			public function execute(CommandSender $sender, string $commandLabel, array $args){
				$this->test->recordLegacy("ownedlegacy", $commandLabel, $args);
				return true;
			}

			public function getOwningPlugin() : Plugin{
				return $this->plugin;
			}
		};
		$legacy->setPermission("test.root");
		$declarative = $this->owned($plugin, "owneddeclarative", function(CommandArgs $args) : void{
			$this->recordLegacy("owneddeclarative", $args->label(), []);
		});
		$this->map->register("owner", $legacy);
		$this->map->register("owner", $declarative);

		$enabled = false;
		$sender = $this->sender();
		self::assertTrue($this->map->dispatch($sender, "ownedlegacy"));
		self::assertTrue($this->map->dispatch($sender, "owneddeclarative"));
		self::assertSame([], $this->calls);
		self::assertSame([], $sender->messages);

		$enabled = true;
		$this->map->dispatch($sender, "ownedlegacy");
		$this->map->dispatch($sender, "owneddeclarative");
		self::assertCount(2, $this->calls);
	}

	public function testPluginDisableUnregistersDeclarativeCommandsEvenWhenDisableThrows() : void{
		$enabled = true;
		$plugin = $this->plugin($enabled, new \RuntimeException("onDisable failed"));
		$manager = new PluginManager($this->server, null);

		$declarative = $this->owned($plugin, "owned");
		$declarative->registerArgument(new IntegerArgument("n", optional: true));
		$manifest = new PluginCommand("manifest", $plugin, self::createStub(CommandExecutor::class));
		$manifest->setPermission("test.root");
		$this->map->register("owner", $declarative);
		$this->map->register("owner", $manifest);
		$this->map->consumeClientMetadataChanges();

		try{
			$manager->disablePlugin($plugin);
			self::fail("onDisable exception should propagate");
		}catch(\RuntimeException $e){
			self::assertSame("onDisable failed", $e->getMessage());
		}
		self::assertFalse($enabled);
		self::assertNull($this->map->getCommand("owned"));
		self::assertNull($this->map->getCommand("owner:owned"));
		self::assertFalse($declarative->isRegistered());
		self::assertNull($declarative->getPreparedSyntaxes());
		self::assertSame($manifest, $this->map->getCommand("manifest"), "legacy manifest commands are kept for re-enable");
		self::assertTrue($this->map->consumeClientMetadataChanges());

		//a retained object can be registered again once the plugin is back
		$enabled = true;
		self::assertTrue($this->map->register("owner", $declarative));
		self::assertTrue($this->map->dispatch($this->sender(), "owned 5"));
	}

	public function testThrowingDisableStillStopsTasksAndListeners() : void{
		$enabled = true;
		$plugin = $this->plugin($enabled, new \RuntimeException("onDisable failed"));
		$manager = new PluginManager($this->server, null);
		$scheduler = $plugin->getScheduler();
		$scheduler->setEnabled(true);
		$task = $scheduler->scheduleDelayedTask(new ClosureTask(function() : void{}), 100);
		$listener = $manager->registerEvent(CommandEvent::class, function(CommandEvent $event) : void{}, EventPriority::NORMAL, $plugin);
		$listeners = HandlerListManager::global()->getListFor(CommandEvent::class);
		self::assertContains($listener, $listeners->getListenersByPriority(EventPriority::NORMAL));

		try{
			$manager->disablePlugin($plugin);
			self::fail("onDisable exception should propagate");
		}catch(\RuntimeException $e){
			self::assertSame("onDisable failed", $e->getMessage());
		}
		self::assertTrue($task->isCancelled());
		self::assertFalse($scheduler->isQueued($task));
		self::assertNotContains($listener, $listeners->getListenersByPriority(EventPriority::NORMAL));
	}

	public function testThrowingDisableDoesNotSkipOtherPluginsAtShutdown() : void{
		$brokenEnabled = true;
		$storageEnabled = true;
		$plugins = [
			"Broken" => $this->plugin($brokenEnabled, new \RuntimeException("onDisable failed"), name: "Broken"),
			"Storage" => $this->plugin($storageEnabled, name: "Storage"),
		];
		$manager = new PluginManager($this->server, null);
		new \ReflectionProperty(PluginManager::class, "enabledPlugins")->setValue($manager, $plugins);
		$manager->disablePlugins();
		self::assertFalse($brokenEnabled);
		self::assertFalse($storageEnabled);
	}

	public function testCleanupFailuresDoNotMaskTheDisableException() : void{
		$enabled = true;
		$scheduler = self::createStub(TaskScheduler::class);
		$scheduler->method("shutdown")->willThrowException(new \LogicException("scheduler failed"));
		$plugin = $this->plugin($enabled, new \RuntimeException("onDisable failed"), $scheduler);
		$manager = new PluginManager($this->server, null);
		$command = $this->owned($plugin, "owned");
		$this->map->register("owner", $command);

		try{
			$manager->disablePlugin($plugin);
			self::fail("onDisable exception should propagate");
		}catch(\RuntimeException $e){
			self::assertSame("onDisable failed", $e->getMessage());
		}
		self::assertNull($this->map->getCommand("owned"));

		//without an onDisable failure the cleanup failure itself is reported
		$enabled = true;
		$quiet = $this->plugin($enabled, null, $scheduler);
		$this->expectExceptionMessage("scheduler failed");
		$manager->disablePlugin($quiet);
	}

	private function help() : Command{
		return $this->map->getCommand("help") ?? throw new \LogicException("Missing help command");
	}

	public function testHelpHidesCommandsOfDisabledPlugins() : void{
		$enabled = true;
		$plugin = $this->plugin($enabled);
		$command = new PluginCommand("manifest", $plugin, self::createStub(CommandExecutor::class));
		$command->setPermission("test.root");
		$this->map->register("owner", $command);
		$this->server->method("getCommandMap")->willReturn($this->map);
		$sender = $this->sender();
		$sender->setBasePermission(DefaultPermissionNames::COMMAND_HELP, true);
		$help = $this->help();

		$help->execute($sender, "help", []);
		self::assertStringContainsString("/manifest", implode("\n", $sender->messages));
		$sender->messages = [];
		$help->execute($sender, "help", ["manifest"]);
		self::assertStringNotContainsString("not found", implode("\n", $sender->messages));

		$enabled = false;
		$sender->messages = [];
		$help->execute($sender, "help", []);
		self::assertStringNotContainsString("/manifest", implode("\n", $sender->messages));
		$sender->messages = [];
		$help->execute($sender, "help", ["manifest"]);
		self::assertSame(["Unknown command: manifest. Use /help for a list of available commands."], $sender->messages);
	}

	public function testPluginCanDisableItselfWhileExecuting() : void{
		$enabled = true;
		$plugin = $this->plugin($enabled);
		$manager = new PluginManager($this->server, null);
		$command = $this->owned($plugin, "quit", function(CommandArgs $args) use ($manager, $plugin) : void{
			$manager->disablePlugin($plugin);
			$this->recordLegacy("quit", $args->label(), []);
		});
		$this->map->register("owner", $command);

		self::assertTrue($this->map->dispatch($this->sender(), "quit"));
		self::assertCount(1, $this->calls);
		self::assertNull($this->map->getCommand("quit"));
		self::assertFalse($this->map->dispatch($this->sender(), "quit"));
	}
}
