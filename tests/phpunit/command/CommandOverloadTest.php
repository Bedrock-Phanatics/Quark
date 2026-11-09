<?php

declare(strict_types=1);

namespace quark\command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use quark\command\argument\Argument;
use quark\command\argument\BooleanArgument;
use quark\command\argument\EnumArgument;
use quark\command\argument\FloatArgument;
use quark\command\argument\IntegerArgument;
use quark\command\argument\LiteralArgument;
use quark\command\argument\PlayerArgument;
use quark\command\argument\PositionArgument;
use quark\command\argument\RelativeFloat;
use quark\command\argument\RelativePosition;
use quark\command\argument\RelativeRotation;
use quark\command\argument\RotationArgument;
use quark\command\argument\StringArgument;
use quark\permission\DefaultPermissions;
use quark\permission\Permission;
use quark\permission\PermissionManager;
use quark\player\Player;
use quark\plugin\Plugin;
use quark\plugin\PluginOwned;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;
use quark\Server;
use function array_reverse;
use function gc_collect_cycles;
use function implode;
use function strtolower;
use function strtr;
use function var_export;

final class CommandOverloadTest extends TestCase{
	private Server&Stub $server;
	private SimpleCommandMap $map;
	/** @phpstan-var array<string, Player> */
	private array $players = [];
	/** @phpstan-var list<string> */
	private array $calls = [];

	protected function setUp() : void{
		Pulse::reset();
		PulseZones::init();
		DefaultPermissions::registerCorePermissions();
		$manager = PermissionManager::getInstance();
		foreach(["test.root", "test.admin"] as $name){
			if($manager->getPermission($name) === null){
				$manager->addPermission(new Permission($name));
			}
		}
		$this->server = self::createStub(Server::class);
		$this->map = new SimpleCommandMap($this->server);
		$this->server->method("getCommandMap")->willReturn($this->map);
		$this->server->method("getPlayerExact")->willReturnCallback(fn(string $name) : ?Player => $this->players[strtolower($name)] ?? null);
		$steve = TestCommandSender::closedPlayer(self::createStub(Player::class));
		$steve->method("getName")->willReturn("Steve");
		$steve->method("getServer")->willReturn($this->server);
		$steve->method("hasPermission")->willReturn(true);
		$this->players["steve"] = $steve;
		$this->calls = [];
	}

	protected function tearDown() : void{
		Pulse::reset();
	}

	private function sender() : TestCommandSender{
		return new TestCommandSender($this->server, ["test.root" => true]);
	}

	private function command(string $name = "x") : Command{
		$command = new class($name) extends Command{};
		$command->setPermission("test.root");
		return $command;
	}

	/**
	 * @phpstan-return list<string>
	 */
	private function dispatch(string $line, ?TestCommandSender $sender = null) : array{
		$sender ??= $this->sender();
		$sender->messages = [];
		$this->calls = [];
		self::assertTrue($this->map->dispatch($sender, $line));
		return $sender->messages;
	}

	private function log(string $entry) : void{
		$this->calls[] = $entry;
	}

	private function position(RelativePosition $position, ?RelativeRotation $rotation = null) : string{
		$format = static fn(RelativeFloat $f) : string => ($f->relative ? "~" : "") . $f->value;
		return implode(",", [$format($position->x), $format($position->y), $format($position->z)]) . ($rotation !== null ? "|" . $format($rotation->yaw) . "," . $format($rotation->pitch) : "");
	}

	/**
	 * @phpstan-return list<\Closure(Command) : mixed>
	 */
	private function tpLikeDefinitions() : array{
		return [
			fn(Command $c) => $c->syntax()->registerArgument(new PlayerArgument("destination"))
				->handler(fn(CommandSender $s, Player $destination) => $this->log("to " . $destination->getName())),
			fn(Command $c) => $c->syntax()->registerArguments([new PlayerArgument("victim"), new PlayerArgument("destination")])
				->handler(fn(CommandSender $s, Player $victim, Player $destination) => $this->log("move " . $victim->getName() . " to " . $destination->getName())),
			fn(Command $c) => $c->syntax()->registerArguments([new PositionArgument("position"), new RotationArgument("rotation", optional: true)])
				->handler(fn(CommandSender $s, RelativePosition $p, ?RelativeRotation $r = null) => $this->log("pos " . $this->position($p, $r))),
			fn(Command $c) => $c->syntax()->registerArguments([new PlayerArgument("victim"), new PositionArgument("position"), new RotationArgument("rotation", optional: true)])
				->handler(fn(CommandSender $s, Player $v, RelativePosition $p, ?RelativeRotation $r = null) => $this->log("move " . $v->getName() . " pos " . $this->position($p, $r))),
		];
	}

	public function testOverloadsWithDifferentLayoutsShareOneCommand() : void{
		$cases = [
			"x steve" => ["to Steve"],
			"x STEVE steve" => ["move Steve to Steve"],
			"x 1 ~2 -3.5" => ["pos 1,~2,-3.5"],
			"x 1 2 3 ~ ~5" => ["pos 1,2,3|~0,~5"],
			"x steve 1 2 3" => ["move Steve pos 1,2,3"],
			"x steve ~ ~ ~ 90 -45" => ["move Steve pos ~0,~0,~0|90,-45"],
		];
		foreach([$this->tpLikeDefinitions(), array_reverse($this->tpLikeDefinitions())] as $definitions){
			$this->map = new SimpleCommandMap($this->server);
			$command = $this->command();
			foreach($definitions as $definition){
				$definition($command);
			}
			$this->map->register("test", $command);
			foreach($cases as $line => $expected){
				self::assertSame([], $this->dispatch($line), $line);
				self::assertSame($expected, $this->calls, $line);
			}
			foreach(["x", "x 1 2", "x 1 2 3 4", "x steve 1 2", "x steve 1 2 3 4", "x nobody", "x 1 2 x"] as $line){
				$messages = $this->dispatch($line);
				self::assertSame([], $this->calls, $line);
				self::assertNotSame([], $messages, $line);
			}
		}
	}

	public function testErrorsPointAtTheFailedArgumentAndPermittedUsages() : void{
		$command = $this->command();
		foreach($this->tpLikeDefinitions() as $definition){
			$definition($command);
		}
		$command->syntax("secret")->permission("test.admin")->handler(fn(CommandSender $s) => $this->log("secret"));
		$this->map->register("test", $command);

		self::assertSame(
			["Invalid value for <destination>: Cannot find a player matching \"nobody\"", "Usage: /x <destination>"],
			$this->dispatch("x nobody")
		);
		self::assertSame(
			["Invalid value for <position>: \"nan\" is not a valid number", "Usage: /x <victim> <x y z> [yaw pitch]"],
			$this->dispatch("x steve 1 nan 3")
		);
		self::assertSame(
			["Invalid value for <victim>: Cannot find a player matching \"1\"", "Usage: /x <victim> <destination>"],
			$this->dispatch("x 1 2")
		);
		self::assertSame(
			["Usage: /x <destination>", "Usage: /x <victim> <destination>", "Usage: /x <x y z> [yaw pitch]", "Usage: /x <victim> <x y z> [yaw pitch]"],
			$this->dispatch("x 1 2 3 4 5 6 7")
		);
		self::assertSame(["You don't have permission to use the command: x"], $this->dispatch("x secret"));
		self::assertSame([], $this->calls);
	}

	public function testLiteralsInTheMiddleAndSeparateHandlers() : void{
		$command = $this->command("fx");
		$command->syntax()->registerArgument(new PlayerArgument("target"))->literal("clear")
			->handler(fn(CommandSender $s, Player $target) => $this->log("clear " . $target->getName()));
		$command->syntax()->registerArguments([new PlayerArgument("target"), new EnumArgument("effect", ["speed", "haste"]), new IntegerArgument("seconds", 1, 1000000, optional: true)])
			->handler(function(CommandSender $s, Player $target, string $effect, ?int $seconds = null) : void{
				$this->log("give $effect " . var_export($seconds, true));
			});
		$this->map->register("test", $command);

		foreach([
			"fx steve clear" => ["clear Steve"],
			"fx Steve CLEAR" => ["clear Steve"],
			"fx steve speed" => ["give speed NULL"],
			"fx steve HASTE 30" => ["give haste 30"],
		] as $line => $expected){
			self::assertSame([], $this->dispatch($line), $line);
			self::assertSame($expected, $this->calls, $line);
		}
		self::assertSame(["Usage: /fx <target> clear", "Usage: /fx <target> <effect> [seconds]"], $this->dispatch("fx steve"));
		self::assertSame(["Invalid value for <effect>: \"clear me\" is not valid, expected one of: speed, haste", "Usage: /fx <target> <effect> [seconds]"], $this->dispatch("fx steve \"clear me\""));
		self::assertSame(["Invalid value for <seconds>: The number you have entered (0) is too small, it must be at least 1", "Usage: /fx <target> <effect> [seconds]"], $this->dispatch("fx steve speed 0"));
		self::assertSame([], $this->calls);
	}

	public function testLiteralsReserveTheirWordAgainstOpenArguments() : void{
		$command = $this->command("lookup");
		$command->syntax()->registerArguments([new StringArgument("name"), new LiteralArgument("clear")])
			->handler(fn(CommandSender $s, string $name) => $this->log("clear $name"));
		$command->syntax()->registerArguments([new StringArgument("name"), new StringArgument("value")])
			->handler(fn(CommandSender $s, string $name, string $value) => $this->log("set $name $value"));
		$this->map->register("test", $command);

		$this->dispatch("lookup a CLEAR");
		$this->dispatch("lookup a b");
		self::assertSame(["set a b"], $this->calls);
		$this->dispatch("lookup a clear");
		self::assertSame(["clear a"], $this->calls);
	}

	public function testResolutionNeverRunsHandlersOrParsesRejectedOverloads() : void{
		/** @var \ArrayObject<int, string> $parsed */
		$parsed = new \ArrayObject();
		$probe = new /**
		 * @phpstan-extends Argument<string>
		 */ class("who", $parsed) extends Argument{
			/** @phpstan-param \ArrayObject<int, string> $parsed */
			public function __construct(string $name, private \ArrayObject $parsed){
				parent::__construct($name);
			}

			public function parse(string $input, CommandSender $sender) : string{
				$this->parsed[] = $input;
				return $input;
			}
		};
		$command = $this->command();
		$command->syntax()->registerArguments([$probe, new IntegerArgument("n")])->handler(fn(CommandSender $s, string $who, int $n) => $this->log("int $n"));
		$command->syntax()->registerArguments([$probe, new EnumArgument("mode", ["on", "off"])])->handler(fn(CommandSender $s, string $who, string $mode) => $this->log("mode $mode"));
		$this->map->register("test", $command);

		$this->dispatch("x alex on");
		self::assertSame(["mode on"], $this->calls);
		self::assertSame(["alex"], $parsed->getArrayCopy());
		$this->dispatch("x alex 5");
		self::assertSame(["int 5"], $this->calls);
		self::assertSame(["alex", "alex"], $parsed->getArrayCopy());
		$this->dispatch("x alex maybe");
		self::assertSame([], $this->calls);
		self::assertSame(["alex", "alex"], $parsed->getArrayCopy(), "no overload matched, nothing may be parsed");
	}

	public function testGreedyTextKeepsTheRawLineButTypedArgumentsAreQuoteAware() : void{
		$command = $this->command("talk");
		$command->syntax()->registerArguments([new StringArgument("first"), new StringArgument("rest", greedy: true)])
			->handler(fn(CommandSender $s, string $first, string $rest) => $this->log("$first|$rest"));
		$this->map->register("test", $command);

		$this->dispatch("talk   \"two words\"   keep   \"this\"  \\\"as is\\\"   ");
		self::assertSame(["two words|keep   \"this\"  \\\"as is\\\""], $this->calls);
		//without the original line the tokens are rejoined
		$this->map->dispatchParsed($this->sender(), "talk", ["a", "b", "c d"]);
		self::assertSame(["two words|keep   \"this\"  \\\"as is\\\"", "a|b c d"], $this->calls);
		self::assertSame(["Usage: /talk <first> <rest...>"], $this->dispatch("talk one"));
		self::assertSame(["Usage: /talk <first> <rest...>"], $this->dispatch("talk"));
	}

	public function testHandlerSignaturesAreCheckedWhenRegistered() : void{
		$invalid = [
			"no parameters" => fn() => null,
			"sender is not a command sender" => fn(int $sender, string $a) => null,
			"too few parameters" => fn(CommandSender $s) => null,
			"too many parameters" => fn(CommandSender $s, string $a, string $b) => null,
			"wrong type" => fn(CommandSender $s, int $a) => null,
			"nullable int for a string" => fn(CommandSender $s, ?int $a) => null,
			"by reference" => fn(CommandSender $s, string &$a) => null,
			"variadic" => fn(CommandSender $s, string ...$a) => null,
		];
		foreach($invalid as $label => $handler){
			$command = $this->command(strtr($label, " ", "-"));
			$command->syntax()->registerArgument(new StringArgument("a"))->handler($handler);
			try{
				$this->map->register("test", $command);
				self::fail("$label should be rejected");
			}catch(\LogicException $e){
				self::assertStringContainsString("Handler of", $e->getMessage(), $label);
			}
			self::assertFalse($command->isRegistered(), $label);
		}

		$optional = $this->command("optional");
		$optional->syntax()->registerArgument(new IntegerArgument("n", optional: true))->handler(fn(CommandSender $s, int $n) => null);
		try{
			$this->map->register("test", $optional);
			self::fail("an optional argument needs a default");
		}catch(\LogicException $e){
			self::assertStringContainsString("default value", $e->getMessage());
		}

		foreach([
			"untyped" => fn($s, $a) => null,
			"mixed" => fn(mixed $s, mixed $a) => null,
			"union" => fn(CommandSender $s, string|int $a) => null,
			"command args" => fn(CommandSender $s, CommandArgs $args) => null,
			"nullable" => fn(CommandSender $s, ?string $a) => null,
		] as $label => $handler){
			$command = $this->command(strtr($label, " ", "-"));
			$command->syntax()->registerArgument(new StringArgument("a"))->handler($handler);
			self::assertTrue($this->map->register("test", $command), $label);
		}
	}

	public function testPlayerTypedSendersRestrictTheSyntaxToPlayers() : void{
		$command = $this->command();
		$command->syntax("here")->handler(function(Player $sender) : void{
			$this->log("here " . $sender->getName());
		});
		$this->map->register("test", $command);

		self::assertSame(["This command syntax can only be used as a player"], $this->dispatch("x here"));
		self::assertSame([], $this->calls);
		$player = $this->players["steve"];
		$this->calls = [];
		self::assertTrue($this->map->dispatch($player, "x here"));
		self::assertSame(["here Steve"], $this->calls);
	}

	public function testCommandArgsHandlersAndRunCanBeMixed() : void{
		$command = new class("x", $this->log(...)) extends Command{
			/** @phpstan-param \Closure(string) : void $log */
			public function __construct(string $name, private \Closure $log){
				parent::__construct($name);
			}

			protected function run(CommandSender $sender, CommandArgs $args) : void{
				($this->log)("run:" . $args->subcommand() . ":" . ($args->has("n") ? $args->int("n") : "-"));
			}
		};
		$command->setPermission("test.root");
		$command->syntax("one")->registerArgument(new IntegerArgument("n"));
		$command->syntax("two")->registerArgument(new IntegerArgument("n", optional: true))
			->handler(fn(CommandSender $s, CommandArgs $args) => $this->log("two:" . ($args->has("n") ? $args->int("n") : "-") . ":" . $args->syntax()->getPath()));
		$this->map->register("test", $command);

		$this->dispatch("x");
		self::assertSame(["run::-"], $this->calls);
		$this->dispatch("x one 4");
		self::assertSame(["run:one:4"], $this->calls);
		$this->dispatch("x two");
		self::assertSame(["two:-:two"], $this->calls);
		$this->dispatch("x two 9");
		self::assertSame(["two:9:two"], $this->calls);
	}

	/**
	 * @phpstan-return \Generator<string, array{\Closure(Command) : mixed, string}, void, void>
	 */
	public static function ambiguityProvider() : \Generator{
		yield "player and word" => [function(Command $c) : void{
			$c->syntax()->registerArgument(new PlayerArgument("p"));
			$c->syntax()->registerArgument(new EnumArgument("mode", ["clear"]));
		}, "are ambiguous"];
		yield "two optional tails" => [function(Command $c) : void{
			$c->syntax("a")->registerArguments([new IntegerArgument("a"), new IntegerArgument("b", optional: true)]);
			$c->syntax("a")->registerArguments([new FloatArgument("a"), new IntegerArgument("c", optional: true)]);
		}, "are ambiguous"];
		yield "same literals different order" => [function(Command $c) : void{
			$c->syntax()->registerArguments([new LiteralArgument("a"), new IntegerArgument("n")]);
			$c->syntax()->registerArguments([new LiteralArgument("a"), new IntegerArgument("m")]);
		}, "are ambiguous"];
		yield "greedy swallows a fixed layout" => [function(Command $c) : void{
			$c->syntax()->registerArguments([new StringArgument("a"), new StringArgument("b")]);
			$c->syntax()->registerArgument(new StringArgument("all", greedy: true));
		}, "are ambiguous"];
		yield "boolean and enum overlap" => [function(Command $c) : void{
			$c->syntax()->registerArgument(new BooleanArgument("flag"));
			$c->syntax()->registerArgument(new EnumArgument("mode", ["true", "other"]));
		}, "are ambiguous"];
	}

	/**
	 * @phpstan-param \Closure(Command) : mixed $definition
	 */
	#[DataProvider("ambiguityProvider")]
	public function testAmbiguousOverloadsAreRejectedBeforeAnyInputIsSeen(\Closure $definition, string $message) : void{
		$command = new class("x") extends Command{
			protected function run(CommandSender $sender, CommandArgs $args) : void{}
		};
		$command->setPermission("test.root");
		$definition($command);
		$this->expectExceptionMessage($message);
		$this->map->register("test", $command);
	}

	/**
	 * @phpstan-return \Generator<string, array{\Closure(Command) : mixed}, void, void>
	 */
	public static function distinctProvider() : \Generator{
		yield "number and enum words" => [function(Command $c) : void{
			$c->syntax("set")->registerArgument(new IntegerArgument("ticks"));
			$c->syntax("set")->registerArgument(new EnumArgument("time", ["day", "night"]));
		}];
		yield "different counts" => [function(Command $c) : void{
			$c->syntax()->registerArgument(new StringArgument("a"));
			$c->syntax()->registerArguments([new StringArgument("a"), new StringArgument("b")]);
		}];
		yield "player and coordinates" => [function(Command $c) : void{
			$c->syntax()->registerArguments([new PlayerArgument("p"), new PositionArgument("pos")]);
			$c->syntax()->registerArgument(new PositionArgument("pos"));
		}];
		yield "same words below different literals" => [function(Command $c) : void{
			$c->syntax("a")->registerArgument(new StringArgument("x"));
			$c->syntax("b")->registerArgument(new StringArgument("x"));
		}];
		yield "string that never takes numbers" => [function(Command $c) : void{
			$c->syntax()->registerArgument(new IntegerArgument("page"));
			$c->syntax()->registerArgument(new StringArgument("name", numbers: false));
		}];
	}

	/**
	 * @phpstan-param \Closure(Command) : mixed $definition
	 */
	#[DataProvider("distinctProvider")]
	public function testDistinctOverloadsCoexist(\Closure $definition) : void{
		$command = new class("x") extends Command{
			protected function run(CommandSender $sender, CommandArgs $args) : void{}
		};
		$command->setPermission("test.root");
		$definition($command);
		self::assertTrue($this->map->register("test", $command));
	}

	public function testSyntaxPermissionsApplyPerOverload() : void{
		$command = $this->command();
		$command->syntax("view")->handler(fn(CommandSender $s) => $this->log("view"));
		$command->syntax("edit")->permission("test.admin")->registerArgument(new IntegerArgument("n"))->handler(fn(CommandSender $s, int $n) => $this->log("edit $n"));
		$this->map->register("test", $command);

		$user = $this->sender();
		self::assertSame(["Usage: /x view"], $this->dispatch("x", $user));
		self::assertSame(["You don't have permission to use the command: x"], $this->dispatch("x edit 5", $user));
		self::assertSame(["You don't have permission to use the command: x"], $this->dispatch("x edit nope", $user), "permission is checked before the arguments");
		self::assertSame([], $this->calls);

		$admin = new TestCommandSender($this->server, ["test.root" => true, "test.admin" => true]);
		self::assertSame([], $this->dispatch("x edit 5", $admin));
		self::assertSame(["edit 5"], $this->calls);
		self::assertSame(["Usage: /x edit <n>", "Usage: /x view"], $this->dispatch("x", $admin));
	}

	public function testPluginCommandsWithHandlersAreUnregisteredAndReleased() : void{
		$plugin = self::createStub(Plugin::class);
		$plugin->method("isEnabled")->willReturn(true);
		$command = new class("owned", $plugin, $this->log(...)) extends Command implements PluginOwned{
			/** @phpstan-param \Closure(string) : void $log */
			public function __construct(string $name, private Plugin $plugin, private \Closure $log){
				parent::__construct($name);
				$this->setPermission("test.root");
				$this->syntax("go")->registerArgument(new IntegerArgument("n"))->handler($this->go(...));
			}

			private function go(CommandSender $sender, int $n) : void{
				($this->log)("go $n");
			}

			public function getOwningPlugin() : Plugin{
				return $this->plugin;
			}
		};
		$this->map->register("test", $command);
		$this->dispatch("owned go 4");
		self::assertSame(["go 4"], $this->calls);

		$reference = \WeakReference::create($command);
		$this->map->unregisterPluginSyntaxCommands($plugin);
		self::assertNull($this->map->getCommand("owned"));
		self::assertFalse($command->isRegistered());
		unset($command);
		//the handler is bound to the command, so PHP's cycle collector frees it
		gc_collect_cycles();
		self::assertNull($reference->get());
	}

	public function testNumbersFailCleanlyAtTheBoundaries() : void{
		$command = $this->command();
		$command->syntax("int")->registerArgument(new IntegerArgument("n"))->handler(fn(CommandSender $s, int $n) => $this->log("int $n"));
		$command->syntax("float")->registerArgument(new FloatArgument("f"))->handler(fn(CommandSender $s, float $f) => $this->log("float $f"));
		$this->map->register("test", $command);

		foreach(["x int 9223372036854775807" => "int 9223372036854775807", "x int -9223372036854775808" => "int -9223372036854775808", "x float 1e300" => "float 1.0E+300"] as $line => $expected){
			$this->dispatch($line);
			self::assertSame([$expected], $this->calls, $line);
		}
		foreach(["x int 9223372036854775808", "x int -9223372036854775809", "x int 1e3", "x int 0x10", "x int 1.5", "x float nan", "x float inf", "x float 1e999", "x float -", "x float 1,5", "x float .", "x int  "] as $line){
			$messages = $this->dispatch($line);
			self::assertSame([], $this->calls, $line);
			self::assertNotSame([], $messages, $line);
		}
	}
}
