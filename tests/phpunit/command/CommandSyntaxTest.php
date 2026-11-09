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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use quark\command\argument\Argument;
use quark\command\argument\ArgumentKind;
use quark\command\argument\ArgumentParseException;
use quark\command\argument\BooleanArgument;
use quark\command\argument\EnumArgument;
use quark\command\argument\FloatArgument;
use quark\command\argument\IntegerArgument;
use quark\command\argument\PlayerArgument;
use quark\command\argument\StringArgument;
use quark\command\defaults\HelpCommand;
use quark\permission\DefaultPermissions;
use quark\permission\Permission;
use quark\permission\PermissionManager;
use quark\player\Player;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;
use quark\Server;
use function array_column;
use function array_keys;
use function array_map;
use function array_reverse;
use function array_values;
use function implode;
use function strtolower;

final class CommandSyntaxTest extends TestCase{
	private Server&Stub $server;
	private SimpleCommandMap $map;
	/** @phpstan-var array<string, Player> */
	private array $players = [];
	/**
	 * @var mixed[][]
	 * @phpstan-var list<array{string, CommandArgs}>
	 */
	private array $calls = [];

	protected function setUp() : void{
		Pulse::reset();
		PulseZones::init();
		DefaultPermissions::registerCorePermissions();
		$manager = PermissionManager::getInstance();
		foreach(["test.root", "test.other", "test.sub", "test.admin"] as $name){
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
		$this->players["steve"] = $steve;
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
	 * @phpstan-return \Closure(CommandArgs) : void
	 */
	private function recorder(string $prefix = "") : \Closure{
		return function(CommandArgs $args) use ($prefix) : void{
			$this->calls[] = [$prefix . $args->subcommand(), $args];
		};
	}

	/**
	 * @param string[] $aliases
	 * @phpstan-param list<string> $aliases
	 */
	private function command(string $name = "eco", array $aliases = []) : Command{
		$command = new class($name, $this->recorder(), $aliases) extends Command{
			/**
			 * @phpstan-param \Closure(CommandArgs) : void $recorder
			 * @phpstan-param list<string> $aliases
			 */
			public function __construct(string $name, private \Closure $recorder, array $aliases){
				parent::__construct($name, "", null, $aliases);
			}

			protected function run(CommandSender $sender, CommandArgs $args) : void{
				($this->recorder)($args);
			}
		};
		$command->setPermission("test.root");
		return $command;
	}

	/**
	 * @phpstan-return list<\Closure(Command) : mixed>
	 */
	private function ecoDefinitions() : array{
		return [
			fn(Command $c) => $c->syntax("balance")->registerArgument(new PlayerArgument("target", optional: true)),
			fn(Command $c) => $c->syntax("pay")->registerArguments([new PlayerArgument("target"), new IntegerArgument("amount", min: 1)]),
			fn(Command $c) => $c->syntax("admin give")->permission("test.admin")->registerArguments([new PlayerArgument("target"), new IntegerArgument("amount", min: 1)]),
		];
	}

	/**
	 * @phpstan-return array<string, mixed>
	 */
	private function values(CommandArgs $args, string ...$names) : array{
		$values = [];
		foreach($names as $name){
			if($args->has($name)){
				$value = $args->get($name);
				$values[$name] = $value instanceof Player ? $value->getName() : $value;
			}
		}
		return $values;
	}

	/**
	 * @phpstan-return list<string>
	 */
	private function dispatch(string $commandLine, ?TestCommandSender $sender = null) : array{
		$sender ??= $this->sender(["test.root" => true, "test.admin" => true]);
		$this->calls = [];
		$sender->messages = [];
		self::assertTrue($this->map->dispatch($sender, $commandLine));
		return $sender->messages;
	}

	public function testRoutingIsDeterministicAndIndependentOfDeclarationOrder() : void{
		$all = ["Usage: /eco", "Usage: /eco admin give <target> <amount>", "Usage: /eco balance [target]", "Usage: /eco pay <target> <amount>"];
		$cases = [
			"eco" => ["", []],
			"eco balance" => ["balance", []],
			"eco balance Steve" => ["balance", ["target" => "Steve"]],
			"eco pay Steve 100" => ["pay", ["target" => "Steve", "amount" => 100]],
			"eco pay steve +007" => ["pay", ["target" => "Steve", "amount" => 7]],
			"eco admin give Steve 100" => ["admin give", ["target" => "Steve", "amount" => 100]],
			"eco pay Steve invalid" => [null, ["Invalid value for <amount>: \"invalid\" is not a valid integer", "Usage: /eco pay <target> <amount>"]],
			"eco pay Steve 0" => [null, ["Invalid value for <amount>: The number you have entered (0) is too small, it must be at least 1", "Usage: /eco pay <target> <amount>"]],
			"eco pay Alex 100" => [null, ["Invalid value for <target>: Cannot find a player matching \"Alex\"", "Usage: /eco pay <target> <amount>"]],
			"eco pay Steve" => [null, ["Usage: /eco pay <target> <amount>"]],
			"eco pay Steve 1 2" => [null, ["Usage: /eco pay <target> <amount>"]],
			"eco balance Steve extra" => [null, ["Usage: /eco balance [target]"]],
			"eco admin" => [null, ["Usage: /eco admin give <target> <amount>"]],
			"eco admin take Steve 1" => [null, ["Usage: /eco admin give <target> <amount>"]],
			"eco unknown" => [null, $all],
			"eco PAY Steve 1" => ["pay", ["target" => "Steve", "amount" => 1]],
		];
		foreach([$this->ecoDefinitions(), array_reverse($this->ecoDefinitions())] as $definitions){
			$this->map = new SimpleCommandMap($this->server);
			$command = $this->command();
			foreach($definitions as $definition){
				$definition($command);
			}
			$this->map->register("test", $command);
			foreach($cases as $input => [$expected, $detail]){
				$messages = $this->dispatch($input);
				if($expected === null){
					self::assertSame([], $this->calls, $input);
					self::assertSame($detail, $messages, $input);
				}else{
					self::assertCount(1, $this->calls, $input);
					[$name, $args] = $this->calls[0];
					self::assertSame($expected, $name, $input);
					self::assertSame($detail, $this->values($args, ...array_keys($detail)), $input);
					self::assertSame([], $messages, $input);
				}
			}
		}
	}

	public function testRootArgumentsNeedNoSyntaxCall() : void{
		$command = $this->command("pay");
		$command->registerArgument(new PlayerArgument("target"));
		$command->registerArguments([new IntegerArgument("amount", min: 1), new StringArgument("note", optional: true, greedy: true)]);
		$this->map->register("test", $command);

		$this->dispatch("pay Steve 5");
		$args = $this->calls[0][1];
		self::assertSame(["Steve", 5, "", ""], [$args->player("target")->getName(), $args->int("amount"), $args->string("note", ""), $args->subcommand()]);
		$this->dispatch("pay Steve 5 thanks a lot");
		self::assertSame("thanks a lot", $this->calls[0][1]->string("note"));
		self::assertSame(["Usage: /pay <target> <amount> [note...]"], $this->dispatch("pay"));
	}

	public function testLiteralsTakePrecedenceAndReserveTheirWords() : void{
		$command = $this->command("faction", ["f"]);
		$command->registerArgument(new StringArgument("name"));
		$command->syntax("home");
		$command->syntax("home set");
		$command->syntax("admin kick")->registerArgument(new PlayerArgument("target"));
		$command->syntax("2");
		$this->map->register("test", $command);

		$this->dispatch("faction home");
		self::assertSame("home", $this->calls[0][0]);
		$this->dispatch("f home set");
		self::assertSame("home set", $this->calls[0][0]);
		self::assertSame("f", $this->calls[0][1]->label());
		$this->dispatch("faction Home SET");
		self::assertSame(["home set"], array_column($this->calls, 0));
		$this->dispatch("faction 2");
		self::assertSame(["2"], array_column($this->calls, 0));
		$this->dispatch("faction Homes");
		self::assertSame(["", ["name" => "Homes"]], [$this->calls[0][0], $this->values($this->calls[0][1], "name")]);
		$this->dispatch("faction \"home set\"");
		self::assertSame(["", ["name" => "home set"]], [$this->calls[0][0], $this->values($this->calls[0][1], "name")]);

		//a reserved word never falls back to the root string argument
		self::assertSame(["Usage: /faction admin kick <target>"], $this->dispatch("faction admin"));
		self::assertSame([], $this->calls);
		self::assertSame(["Usage: /faction home", "Usage: /faction home set"], $this->dispatch("faction home extra"));
		self::assertSame([], $this->calls);
	}

	public function testOptionalValuesGreedyTextAndArgsAccess() : void{
		$command = $this->command();
		$command->syntax("claim")->registerArgument(new IntegerArgument("radius", min: 1, max: 8, optional: true));
		$command->syntax("say")->registerArgument(new StringArgument("message", greedy: true));
		$command->syntax("set")->registerArguments([new EnumArgument("mode", ["on", "off"]), new BooleanArgument("loud", optional: true), new FloatArgument("speed", optional: true)]);
		$this->map->register("test", $command);

		$this->dispatch("eco claim");
		self::assertFalse($this->calls[0][1]->has("radius"));
		self::assertSame(1, $this->calls[0][1]->int("radius", 1));
		$this->dispatch("eco claim 8");
		self::assertSame(8, $this->calls[0][1]->int("radius", 1));
		self::assertSame(["Invalid value for <radius>: The number you have entered (9) is too big, it must be at most 8", "Usage: /eco claim [radius]"], $this->dispatch("eco claim 9"));

		$this->dispatch("eco say  hello   \"big   world\" \\\"x\\\"  \"\" end");
		self::assertSame("hello   \"big   world\" \\\"x\\\"  \"\" end", $this->calls[0][1]->string("message"));
		self::assertSame(["Usage: /eco say <message...>"], $this->dispatch("eco say"));

		$this->dispatch("eco set on");
		$args = $this->calls[0][1];
		self::assertSame("on", $args->string("mode"));
		self::assertFalse($args->has("loud"));
		self::assertFalse($args->bool("loud", false));
		self::assertSame(1.5, $args->float("speed", 1.5));
		self::assertFalse($args->has("unknown"));
		$this->dispatch("eco set off false 2.5");
		self::assertSame([false, 2.5], [$this->calls[0][1]->bool("loud", true), $this->calls[0][1]->float("speed")]);
		self::assertSame(["Invalid value for <mode>: \"maybe\" is not valid, expected one of: on, off", "Usage: /eco set <mode> [loud] [speed]"], $this->dispatch("eco set maybe nope 1"));

		foreach([
			fn() => $args->get("loud"),
			fn() => $args->string("unknown"),
			fn() => $args->int("mode"),
			fn() => $args->float("mode"),
			fn() => $args->bool("mode"),
			fn() => $args->player("mode"),
			fn() => $args->int("mode", 1),
		] as $misuse){
			try{
				$misuse();
				self::fail("misuse should be a programmer error");
			}catch(\LogicException){
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testRootAndSyntaxPermissionsAreBothEnforcedWithoutFallback() : void{
		$command = $this->command();
		$command->setPermissions(["test.root", "test.other"]);
		$command->registerArgument(new StringArgument("text", greedy: true));
		$command->syntax("secret")->permission("test.admin");
		$command->syntax("visible")->registerArgument(new StringArgument("a"));
		$this->map->register("test", $command);

		$denied = "You don't have permission to use the command: eco";
		self::assertSame([$denied], $this->dispatch("eco secret", $this->sender(["test.admin" => true])));
		self::assertSame([], $this->calls);
		self::assertSame([$denied], $this->dispatch("eco secret", $this->sender([DefaultPermissions::ROOT_CONSOLE => true])));

		$user = $this->sender(["test.other" => true]);
		self::assertSame([$denied], $this->dispatch("eco secret", $user));
		self::assertSame([], $this->calls, "denied subcommands must not fall back to the root syntax");
		self::assertSame(["Usage: /eco visible <a>"], $this->dispatch("eco visible", $user));
		self::assertSame(["Usage: /eco <text...>", "Usage: /eco visible <a>"], $this->dispatch("eco \"\"", $user));

		$user->setBasePermission("test.admin", true);
		self::assertSame([], $this->dispatch("eco secret", $user));
		self::assertSame(["secret"], array_column($this->calls, 0));
		$user->unsetBasePermission("test.admin");
		$this->dispatch("eco secret", $user);
		self::assertSame([], $this->calls);

		$command->setPermissionMessage("");
		self::assertSame([], $this->dispatch("eco secret", $user));
		$command->setPermissionMessage("missing <permission>");
		self::assertSame(["missing test.admin"], $this->dispatch("eco secret", $user));
		self::assertSame(["missing test.root;test.other"], $this->dispatch("eco secret", $this->sender([])));
	}

	public function testParseFailureStopsBeforeLaterArgumentsAndHandler() : void{
		/** @var \ArrayObject<int, string> $parsed */
		$parsed = new \ArrayObject();
		$command = $this->command();
		$probe = fn(string $name) => new /**
		 * @phpstan-extends Argument<string>
		 */ class($name, $parsed) extends Argument{
			/** @phpstan-param \ArrayObject<int, string> $parsed */
			public function __construct(string $name, private \ArrayObject $parsed){
				parent::__construct($name);
			}

			public function parse(string $input, CommandSender $sender) : string{
				$this->parsed[] = $input;
				if($input === "bad"){
					throw new ArgumentParseException("bad input");
				}
				return $input;
			}
		};
		$command->registerArguments([$probe("a"), $probe("b"), $probe("c")]);
		$this->map->register("test", $command);

		self::assertSame(["Invalid value for <b>: bad input", "Usage: /eco <a> <b> <c>"], $this->dispatch("eco ok bad bad"));
		self::assertSame(["ok", "bad"], $parsed->getArrayCopy());
		self::assertSame([], $this->calls);
	}

	public function testHandlerExceptionsPropagate() : void{
		$command = new class("eco") extends Command{
			protected function run(CommandSender $sender, CommandArgs $args) : void{
				throw new \RuntimeException("handler bug");
			}
		};
		$command->setPermission("test.root");
		$this->map->register("test", $command);

		$this->expectExceptionMessage("handler bug");
		$this->dispatch("eco");
	}

	/**
	 * @phpstan-return \Generator<string, array{\Closure(Command) : mixed}, void, void>
	 */
	public static function ambiguousProvider() : \Generator{
		yield "root" => [function(Command $c) : void{
			$c->registerArgument(new StringArgument("a"));
			$c->syntax()->registerArgument(new StringArgument("b"));
		}];
		yield "literal" => [fn(Command $c) => [$c->syntax("pay"), $c->syntax(" pay ")]];
		yield "number and decimal" => [function(Command $c) : void{
			$c->syntax("x")->registerArgument(new IntegerArgument("a"));
			$c->syntax("x")->registerArgument(new FloatArgument("b"));
		}];
		yield "word and enum" => [function(Command $c) : void{
			$c->syntax("x")->registerArgument(new StringArgument("a"));
			$c->syntax("x")->registerArgument(new EnumArgument("b", ["on", "off"]));
		}];
		yield "optional tails" => [function(Command $c) : void{
			$c->syntax("x")->registerArguments([new IntegerArgument("a"), new IntegerArgument("b", optional: true)]);
			$c->syntax("x")->registerArguments([new IntegerArgument("c"), new StringArgument("d", optional: true)]);
		}];
	}

	/**
	 * @phpstan-param \Closure(Command) : mixed $definition
	 */
	#[DataProvider("ambiguousProvider")]
	public function testAmbiguousSyntaxesAreRejectedWhenRegistered(\Closure $definition) : void{
		$command = $this->command();
		$definition($command);
		$this->expectExceptionMessage("are ambiguous");
		$this->map->register("test", $command);
	}

	public function testInvalidDefinitionsLeaveTheMapUnchanged() : void{
		$existing = $this->command("other", ["eco"]);
		$this->map->register("test", $existing);
		$before = $this->map->getCommands();
		$this->map->consumeClientMetadataChanges();

		$command = $this->command("eco", ["e"]);
		$command->syntax("x")->permission("test.missing");
		try{
			$this->map->register("test", $command);
			self::fail("registration should fail");
		}catch(\InvalidArgumentException){
			$this->addToAssertionCount(1);
		}
		self::assertSame($before, $this->map->getCommands());
		self::assertFalse($command->isRegistered());
		self::assertSame("eco", $command->getLabel());
		self::assertSame(["e"], $command->getAliases());
		self::assertFalse($this->map->consumeClientMetadataChanges());
		$command->syntax("still-mutable");
	}

	public function testDefinitionShapeIsValidatedWhenDeclared() : void{
		$command = $this->command();
		foreach([
			fn() => $command->syntax("Admin"),
			fn() => $command->syntax("a.b"),
			fn() => $command->syntax("r1")->registerArguments([new StringArgument("a", optional: true), new StringArgument("b")]),
			fn() => $command->syntax("r2")->registerArguments([new StringArgument("a", greedy: true), new StringArgument("b", optional: true)]),
			fn() => $command->syntax("r3")->registerArguments([new StringArgument("a"), new IntegerArgument("a")]),
		] as $definition){
			try{
				$definition();
				self::fail("definition should be rejected");
			}catch(\InvalidArgumentException){
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testPathWhitespaceIsCanonicalized() : void{
		$command = $this->command();
		$syntax = $command->syntax("  home \t set ");
		self::assertSame("home set", $syntax->getPath());
		$this->map->register("test", $command);
		$this->dispatch("eco home set");
		self::assertSame("home set", $this->calls[0][0]);
	}

	public function testHandlersAreCheckedAtRegistration() : void{
		$cases = [
			"cannot override execute()" => new class("both") extends Command{
				public function execute(CommandSender $sender, string $commandLabel, array $args){
					return null;
				}

				protected function run(CommandSender $sender, CommandArgs $args) : void{}
			},
			"must override run() or execute()" => new class("neither") extends Command{},
			"needs a handler, or the command must override run()" => new class("nohandler") extends Command{},
		];
		$cases["needs a handler, or the command must override run()"]->syntax("x");
		$legacyWithSyntax = new class("mixed") extends Command{
			public function execute(CommandSender $sender, string $commandLabel, array $args){
				return null;
			}
		};
		$legacyWithSyntax->syntax("x");
		foreach([...array_values($cases), $legacyWithSyntax] as $command){
			$command->setPermission("test.root");
		}
		foreach($cases as $message => $command){
			try{
				$this->map->register("test", $command);
				self::fail("$message should be rejected");
			}catch(\LogicException $e){
				self::assertStringContainsString($message, $e->getMessage());
			}
		}
		$this->expectExceptionMessage("cannot override execute()");
		$this->map->register("test", $legacyWithSyntax);
	}

	public function testUnoverriddenRunReportsTheCommand() : void{
		$command = new class("warp") extends Command{};
		$method = new \ReflectionMethod(Command::class, "run");
		$this->expectExceptionMessage("must override run()");
		$method->invoke($command, $this->sender(), new CommandArgs("warp", new CommandSyntax(), []));
	}

	public function testDefinitionsCannotChangeWhileRegistered() : void{
		$command = $this->command("eco", ["e"]);
		$syntax = $command->syntax("pay");
		$this->map->consumeClientMetadataChanges();
		self::assertTrue($this->map->register("test", $command));
		self::assertTrue($this->map->consumeClientMetadataChanges());
		self::assertFalse($this->map->consumeClientMetadataChanges());

		foreach([
			fn() => $command->syntax("late"),
			fn() => $command->registerArgument(new StringArgument("late")),
			fn() => $command->registerArguments([new StringArgument("late")]),
		] as $mutation){
			try{
				$mutation();
				self::fail("definitions must not change while registered");
			}catch(\LogicException){
				$this->addToAssertionCount(1);
			}
		}

		try{
			$this->map->register("test", $command);
			self::fail("repeated registration should be rejected");
		}catch(\InvalidArgumentException){
			$this->addToAssertionCount(1);
		}
		$foreign = new SimpleCommandMap($this->server);
		$before = $foreign->getCommands();
		try{
			$foreign->register("test", $command);
			self::fail("foreign registration should be rejected");
		}catch(\InvalidArgumentException){
			self::assertSame($before, $foreign->getCommands());
		}

		$command->setDescription("changed");
		self::assertTrue($this->map->consumeClientMetadataChanges());

		$this->map->unregister($command);
		self::assertTrue($this->map->consumeClientMetadataChanges());
		self::assertNull($this->map->getCommand("eco"));
		self::assertNull($this->map->getCommand("e"));
		self::assertNull($this->map->getCommand("test:eco"));
		self::assertNull($command->getPreparedSyntaxes());
		$command->setDescription("unregistered");
		self::assertFalse($this->map->consumeClientMetadataChanges());
		try{
			$command->execute($this->sender(), "eco", []);
			self::fail("unprepared syntaxes must not execute");
		}catch(\LogicException){
			$this->addToAssertionCount(1);
		}

		self::assertTrue($this->map->register("test", $command));
		self::assertSame(["", "pay"], array_map(fn(CommandSyntax $s) => $s->getPath(), $command->getPreparedSyntaxes() ?? []));
		self::assertSame([$syntax], $command->getSyntaxes());
		self::assertContains($syntax, $command->getPreparedSyntaxes() ?? []);
		$this->dispatch("e");
		self::assertSame("", $this->calls[0][0]);
	}

	public function testRetainedSyntaxesAreLockedWhileRegistered() : void{
		$command = $this->command();
		$syntax = $command->syntax("admin")->permission("test.admin");
		$this->map->register("test", $command);

		foreach([
			fn() => $syntax->permission(null),
			fn() => $syntax->registerArgument(new StringArgument("late")),
			fn() => $syntax->registerArguments([new StringArgument("late")]),
		] as $mutation){
			try{
				$mutation();
				self::fail("registered definitions must not change");
			}catch(\LogicException){
				$this->addToAssertionCount(1);
			}
		}
		self::assertSame("test.admin", $syntax->getPermission());

		$this->map->unregister($command);
		$syntax->permission("test.sub");
		self::assertSame("test.sub", $syntax->getPermission());
	}

	public function testFailedRegistrationLeavesDefinitionsEditable() : void{
		$command = $this->command();
		$syntax = $command->syntax("x")->permission("test.missing");
		try{
			$this->map->register("test", $command);
			self::fail("registration should fail");
		}catch(\InvalidArgumentException){
			$syntax->permission("test.admin");
		}
		self::assertTrue($this->map->register("test", $command));
	}

	public function testHelpListsThePermittedSyntaxes() : void{
		$command = $this->command();
		$command->syntax("pay")->registerArgument(new IntegerArgument("amount"));
		$command->syntax("admin")->permission("test.admin");
		$this->map->register("test", $command);
		$custom = $this->command("custom");
		$custom->setUsage("/custom <anything>");
		$custom->syntax("pay");
		$this->map->register("test", $custom);
		$sender = $this->sender([DefaultPermissions::ROOT_USER => true, "test.root" => true]);

		$help = new HelpCommand();
		$help->execute($sender, "help", ["eco"]);
		$output = implode("\n", $sender->messages);
		self::assertStringContainsString("/eco\n", $output);
		self::assertStringContainsString("/eco pay <amount>", $output);
		self::assertStringNotContainsString("/eco admin", $output);

		$sender->messages = [];
		$help->execute($sender, "help", ["custom"]);
		$output = implode("\n", $sender->messages);
		self::assertStringContainsString("/custom <anything>", $output);
		self::assertStringNotContainsString("/custom pay", $output);
	}

	public function testEnumKindNeedsValues() : void{
		$command = $this->command();
		$command->registerArgument(new /**
		 * @phpstan-extends Argument<string>
		 */ class("zone") extends Argument{
			public function parse(string $input, CommandSender $sender) : string{
				return $input;
			}

			public function getKind() : ArgumentKind{
				return ArgumentKind::ENUM;
			}
		});
		$this->expectExceptionMessage("needs an enum");
		$this->map->register("test", $command);
	}

	public function testFallbackLabelSurvivesReregistration() : void{
		$this->map->register("test", $this->command("eco"));
		$command = $this->command("eco");
		self::assertFalse($this->map->register("plugin", $command));
		self::assertSame("plugin:eco", $command->getLabel());

		$this->map->unregister($command);
		$this->map->register("plugin", $command);
		$this->dispatch("plugin:eco");
		self::assertCount(1, $this->calls);
	}

	public function testFailingOverriddenRegisterRestoresMapState() : void{
		$legacy = new class("legacy", "", null, ["lg"]) extends Command{
			public bool $accept = false;

			public function register(CommandMap $commandMap) : bool{
				if(!$this->accept){
					throw new \RuntimeException("plugin failure");
				}
				return parent::register($commandMap);
			}

			public function execute(CommandSender $sender, string $commandLabel, array $args){
				return true;
			}
		};
		$legacy->setPermission("test.root");
		$before = $this->map->getCommands();
		try{
			$this->map->register("test", $legacy);
			self::fail("register override should throw");
		}catch(\RuntimeException){
			self::assertSame($before, $this->map->getCommands());
		}
		self::assertSame(["legacy", ["lg"]], [$legacy->getLabel(), $legacy->getAliases()]);
		$legacy->accept = true;
		self::assertTrue($this->map->register("test", $legacy));
		self::assertSame($legacy, $this->map->getCommand("lg"));
	}

	public function testFailureAfterParentRegisterUnbindsTheCommand() : void{
		$command = new class("eco", "", null, ["e"]) extends Command{
			public ?string $failure = "throw";

			public function register(CommandMap $commandMap) : bool{
				$registered = parent::register($commandMap);
				return match($this->failure){
					"throw" => throw new \RuntimeException("after parent"),
					"false" => false,
					default => $registered
				};
			}

			protected function run(CommandSender $sender, CommandArgs $args) : void{}
		};
		$command->setPermission("test.root");
		$taken = $this->command("taken", ["e"]);
		$this->map->register("test", $taken);
		$before = $this->map->getCommands();
		$this->map->consumeClientMetadataChanges();

		foreach(["throw", "false"] as $failure){
			$command->failure = $failure;
			try{
				self::assertFalse($this->map->register("test", $command), $failure);
			}catch(\RuntimeException $e){
				self::assertSame("after parent", $e->getMessage());
			}
			self::assertSame($before, $this->map->getCommands(), $failure);
			self::assertFalse($command->isRegistered(), $failure);
			self::assertSame(["eco", ["e"]], [$command->getLabel(), $command->getAliases()], $failure);
			self::assertNull($command->getPreparedSyntaxes(), $failure);
			self::assertFalse($this->map->consumeClientMetadataChanges(), $failure);
		}

		$command->failure = null;
		self::assertTrue($this->map->register("test", $command));
		self::assertSame(["eco", []], [$command->getLabel(), $command->getAliases()]);
		self::assertSame($taken, $this->map->getCommand("e"));
	}

	public function testPreparedSyntaxesUseCanonicalOrder() : void{
		$command = $this->command();
		$command->syntax("b");
		$command->syntax("a c");
		$command->syntax("a");
		$this->map->register("test", $command);
		self::assertSame(["", "a", "a c", "b"], array_map(fn(CommandSyntax $s) => $s->getPath(), $command->getPreparedSyntaxes() ?? []));
	}

	public function testUnregisteredCommandsAreFreedWithoutTheCycleCollector() : void{
		$command = new class("eco") extends Command{
			protected function run(CommandSender $sender, CommandArgs $args) : void{}
		};
		$command->setPermission("test.root");
		$command->syntax("pay")->registerArgument(new IntegerArgument("amount"));
		$reference = \WeakReference::create($command);

		$this->map->register("test", $command);
		$this->map->unregister($command);
		unset($command);
		self::assertNull($reference->get());
	}
}
