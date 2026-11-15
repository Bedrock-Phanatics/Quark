<?php

declare(strict_types=1);

namespace quark;

use PHPUnit\Framework\TestCase;
use quark\command\Command;
use quark\command\CommandSender;
use quark\command\SimpleCommandMap;
use quark\command\TestCommandSender;
use quark\console\ConsoleCommandSender;
use quark\event\EventPriority;
use quark\event\HandlerListManager;
use quark\event\RegisteredListener;
use quark\event\server\CommandEvent;
use quark\permission\DefaultPermissions;
use quark\plugin\Plugin;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;
use quark\utils\MainLogger;
use function array_column;

final class CommandLogCapture extends MainLogger{
	/** @var list<array{string, string}> */
	public static array $events = [];

	public function event(string $level, string $message, string $source = "Server", array $context = [], string $category = "log", array $exceptions = [], bool $force = false) : void{
		if($category === "command"){
			self::$events[] = [$source, $message];
		}
	}
}

final class ServerCommandLogTest extends TestCase{
	private Server $server;
	private SimpleCommandMap $map;
	public int $executed = 0;
	/** @var RegisteredListener<CommandEvent>|null */
	private ?RegisteredListener $listener = null;

	protected function setUp() : void{
		Pulse::reset();
		PulseZones::init();
		DefaultPermissions::registerCorePermissions();
		$stub = self::createStub(Server::class);
		$stub->method("getCommandAliases")->willReturn([]);
		$this->map = new SimpleCommandMap($stub);
		$stub->method("getCommandMap")->willReturn($this->map);

		CommandLogCapture::$events = [];
		$logger = new \ReflectionClass(CommandLogCapture::class)->newInstanceWithoutConstructor();
		$this->server = new \ReflectionClass(Server::class)->newInstanceWithoutConstructor();
		new \ReflectionProperty(Server::class, "logger")->setValue($this->server, $logger);
		new \ReflectionProperty(Server::class, "commandMap")->setValue($this->server, $this->map);

		$this->register("gift", ["gf"]);
		$this->register("login", []);
	}

	protected function tearDown() : void{
		Pulse::reset();
		if($this->listener !== null){
			HandlerListManager::global()->getListFor(CommandEvent::class)->unregister($this->listener);
		}
	}

	/** @param list<string> $aliases */
	private function register(string $name, array $aliases) : void{
		$command = new class($name, $aliases, $this) extends Command{
			/** @param list<string> $aliases */
			public function __construct(string $name, array $aliases, private ServerCommandLogTest $test){
				parent::__construct($name, "", null, $aliases);
			}

			public function execute(CommandSender $sender, string $commandLabel, array $args){
				$this->test->executed++;
				return true;
			}
		};
		$command->setPermission("quark.command.help");
		$this->map->register("test", $command);
	}

	private function console() : ConsoleCommandSender{
		return new ConsoleCommandSender($this->server, TestCommandSender::language());
	}

	private function player() : TestCommandSender{
		return new TestCommandSender($this->server, ["quark.command.help" => true]);
	}

	/** @param \Closure(CommandEvent) : void $handler */
	private function listen(\Closure $handler) : void{
		$this->listener = new RegisteredListener($handler, EventPriority::NORMAL, $this->createMock(Plugin::class), true, Pulse::zone("test.command_log"));
		HandlerListManager::global()->getListFor(CommandEvent::class)->register($this->listener);
	}

	public function testConsoleKeepsQuotesAndSpacing() : void{
		$this->server->dispatchCommand($this->console(), 'gift Steve "diamond sword"  64');
		self::assertSame([["Console", '/gift Steve "diamond sword"  64']], CommandLogCapture::$events);
		self::assertSame(1, $this->executed);
	}

	public function testPlayerSourceIsSenderName() : void{
		$this->server->dispatchCommand($this->player(), "gift Steve diamond 64");
		self::assertSame([["tester", "/gift Steve diamond 64"]], CommandLogCapture::$events);
	}

	public function testAliasAndNamespacedLabelsAreKept() : void{
		$this->server->dispatchCommand($this->console(), "gf Steve dirt");
		$this->server->dispatchCommand($this->console(), "test:gift Steve dirt");
		self::assertSame(["/gf Steve dirt", "/test:gift Steve dirt"], array_column(CommandLogCapture::$events, 1));
		self::assertSame(2, $this->executed);
	}

	public function testUnknownCommandDropsArguments() : void{
		$this->server->dispatchCommand($this->console(), "logn hunter2");
		self::assertSame([["Console", "/logn [unknown command]"]], CommandLogCapture::$events);
	}

	public function testSensitiveCommandsAreRedactedThroughAliases() : void{
		$this->server->dispatchCommand($this->player(), "login hunter2");
		$this->server->dispatchCommand($this->player(), "msg Alex the secret");
		$this->server->dispatchCommand($this->player(), "quark:tell Alex the secret");
		self::assertSame(["/login [redacted]", "/msg [redacted]", "/quark:tell [redacted]"], array_column(CommandLogCapture::$events, 1));
		self::assertSame(1, $this->executed);
	}

	public function testControlCharactersCannotForgeEntries() : void{
		$this->server->dispatchCommand($this->console(), "gift Steve\n\x1b[31mforged\r\x00 entry");
		self::assertCount(1, CommandLogCapture::$events);
		self::assertSame("/gift Steve  [31mforged   entry", CommandLogCapture::$events[0][1]);
	}

	public function testCancelledCommandIsNotLogged() : void{
		$this->listen(static fn(CommandEvent $e) => $e->cancel());
		self::assertFalse($this->server->dispatchCommand($this->console(), "gift Steve dirt"));
		self::assertSame([], CommandLogCapture::$events);
		self::assertSame(0, $this->executed);
	}

	public function testModifiedCommandLogsWhatRuns() : void{
		$this->listen(static fn(CommandEvent $e) => $e->setCommand("gift Alex stone 1"));
		$this->server->dispatchCommand($this->console(), "gift Steve dirt");
		self::assertSame([["Console", "/gift Alex stone 1"]], CommandLogCapture::$events);
	}

	public function testInternalDispatchIsNotLogged() : void{
		$this->server->dispatchCommand($this->console(), "gift Steve dirt", true);
		self::assertSame([], CommandLogCapture::$events);
		self::assertSame(1, $this->executed);
	}
}
