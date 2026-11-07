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

namespace quark\command\argument;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use quark\command\TestCommandSender;
use quark\lang\Translatable;
use quark\player\Player;
use quark\pulse\internal\PulseZones;
use quark\pulse\Pulse;
use quark\Server;
use function strtolower;
use const INF;
use const NAN;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

final class ArgumentTest extends TestCase{

	protected function setUp() : void{
		Pulse::reset();
		PulseZones::init();
	}

	protected function tearDown() : void{
		Pulse::reset();
	}

	private function sender() : TestCommandSender{
		$steve = TestCommandSender::closedPlayer(self::createStub(Player::class));
		$steve->method("getName")->willReturn("Steve");
		$server = self::createStub(Server::class);
		$server->method("getPlayerExact")->willReturnCallback(fn(string $name) : ?Player => strtolower($name) === "steve" ? $steve : null);
		return new TestCommandSender($server, []);
	}

	/**
	 * @phpstan-return \Generator<int, array{IntegerArgument, string, int|null}, void, void>
	 */
	public static function integerProvider() : \Generator{
		$any = new IntegerArgument("n");
		yield [$any, "0", 0];
		yield [$any, "-0", 0];
		yield [$any, "+42", 42];
		yield [$any, "007", 7];
		yield [$any, "-12", -12];
		yield [$any, "9223372036854775807", PHP_INT_MAX];
		yield [$any, "-9223372036854775808", PHP_INT_MIN];
		yield [$any, "0009223372036854775807", PHP_INT_MAX];
		yield [$any, "9223372036854775808", null];
		yield [$any, "-9223372036854775809", null];
		yield [$any, "99999999999999999999", null];
		yield [$any, "1.5", null];
		yield [$any, "1e3", null];
		yield [$any, "0x10", null];
		yield [$any, "", null];
		yield [$any, "+", null];
		yield [$any, " 1", null];
		yield [$any, "1 ", null];
		yield [$any, "true", null];
		yield [$any, "１", null];

		$bounded = new IntegerArgument("n", min: 1, max: 10);
		yield [$bounded, "1", 1];
		yield [$bounded, "10", 10];
		yield [$bounded, "0", null];
		yield [$bounded, "11", null];
		yield [$bounded, "-9223372036854775808", null];
	}

	#[DataProvider("integerProvider")]
	public function testIntegerParsing(IntegerArgument $argument, string $input, ?int $expected) : void{
		$this->assertParse($argument, $input, $expected);
	}

	/**
	 * @phpstan-return \Generator<int, array{FloatArgument, string, float|null}, void, void>
	 */
	public static function floatProvider() : \Generator{
		$any = new FloatArgument("f");
		yield [$any, "1", 1.0];
		yield [$any, "-1.5", -1.5];
		yield [$any, "+.5", 0.5];
		yield [$any, "1.", 1.0];
		yield [$any, "1e3", 1000.0];
		yield [$any, "2.5E-2", 0.025];
		yield [$any, "1e-400", 0.0];
		yield [$any, "1e400", null];
		yield [$any, "-1e400", null];
		yield [$any, "NAN", null];
		yield [$any, "INF", null];
		yield [$any, "1,5", null];
		yield [$any, "0x1A", null];
		yield [$any, ".", null];
		yield [$any, "e5", null];
		yield [$any, "1e", null];
		yield [$any, "", null];

		$bounded = new FloatArgument("f", min: 0.0, max: 1.0);
		yield [$bounded, "0", 0.0];
		yield [$bounded, "1.0", 1.0];
		yield [$bounded, "-0.01", null];
		yield [$bounded, "1.01", null];
	}

	#[DataProvider("floatProvider")]
	public function testFloatParsing(FloatArgument $argument, string $input, ?float $expected) : void{
		$this->assertParse($argument, $input, $expected);
	}

	/**
	 * @phpstan-return \Generator<int, array{Argument<mixed>, string, mixed}, void, void>
	 */
	public static function wordProvider() : \Generator{
		$bool = new BooleanArgument("b");
		yield [$bool, "true", true];
		yield [$bool, "false", false];
		yield [$bool, "TRUE", null];
		yield [$bool, "1", null];
		yield [$bool, "yes", null];

		$enum = new EnumArgument("mode", ["survival", "creative", "0"]);
		yield [$enum, "survival", "survival"];
		yield [$enum, "0", "0"];
		yield [$enum, "Survival", null];
		yield [$enum, "adventure", null];
		yield [$enum, "00", null];

		$string = new StringArgument("s");
		yield [$string, "Hello World", "Hello World"];
		yield [$string, "\"quoted\"", "\"quoted\""];
	}

	/**
	 * @phpstan-param Argument<mixed> $argument
	 */
	#[DataProvider("wordProvider")]
	public function testWordParsing(Argument $argument, string $input, mixed $expected) : void{
		$this->assertParse($argument, $input, $expected);
	}

	public function testPlayerLookupIsExactAndRejectsSelectors() : void{
		$argument = new PlayerArgument("target");
		$sender = $this->sender();
		self::assertSame("Steve", $argument->parse("steve", $sender)->getName());
		self::assertSame("Steve", $argument->parse("STEVE", $sender)->getName());
		foreach(["Ste", "Steven", "@p", "@a", "@s", "@e[type=player]", "@"] as $input){
			try{
				$argument->parse($input, $sender);
				self::fail("\"$input\" should not resolve to a player");
			}catch(ArgumentParseException){
				$this->addToAssertionCount(1);
			}
		}
	}

	public function testParseErrorsAreTranslatable() : void{
		$sender = $this->sender();
		$messages = [];
		foreach([
			[new IntegerArgument("n", min: 1), "0"],
			[new IntegerArgument("n", max: 5), "99999999999999999999"],
			[new IntegerArgument("n"), "x"],
			[new FloatArgument("f"), "1e400"],
			[new BooleanArgument("b"), "maybe"],
			[new EnumArgument("e", ["a", "b"]), "c"],
			[new PlayerArgument("p"), "@a"],
			[new PlayerArgument("p"), "Alex"],
		] as [$argument, $input]){
			try{
				$argument->parse($input, $sender);
				self::fail("expected parse failure for \"$input\"");
			}catch(ArgumentParseException $e){
				$message = $e->getUserMessage();
				$messages[] = $message instanceof Translatable ? TestCommandSender::language()->translate($message) : $message;
			}
		}
		self::assertSame([
			"The number you have entered (0) is too small, it must be at least 1",
			"The number you have entered (99999999999999999999) is too big, it must be at most 5",
			"\"x\" is not a valid integer",
			"\"1e400\" is not a valid number",
			"\"maybe\" is not a valid boolean, expected true or false",
			"\"c\" is not valid, expected one of: a, b",
			"Target selectors such as \"@a\" are not supported",
			"Cannot find a player matching \"Alex\"",
		], $messages);
	}

	/**
	 * @phpstan-return \Generator<int, array{\Closure() : mixed}, void, void>
	 */
	public static function invalidConfigurationProvider() : \Generator{
		yield [fn() => new StringArgument("1abc")];
		yield [fn() => new StringArgument("has space")];
		yield [fn() => new StringArgument("")];
		yield [fn() => new IntegerArgument("n", min: 5, max: 4)];
		yield [fn() => new FloatArgument("f", min: NAN)];
		yield [fn() => new FloatArgument("f", max: INF)];
		yield [fn() => new FloatArgument("f", min: 1.0, max: 0.0)];
		yield [fn() => new EnumArgument("e", [])];
		yield [fn() => new EnumArgument("e", ["a", "a"])];
		yield [fn() => new EnumArgument("e", ["A"])];
		yield [fn() => new EnumArgument("e", ["a b"])];
	}

	/**
	 * @phpstan-param \Closure() : mixed $factory
	 */
	#[DataProvider("invalidConfigurationProvider")]
	public function testInvalidConfigurationIsRejected(\Closure $factory) : void{
		$this->expectException(\InvalidArgumentException::class);
		$factory();
	}

	/**
	 * @phpstan-return \Generator<int, array{mixed[]}, void, void>
	 */
	public static function invalidEnumValuesProvider() : \Generator{
		yield [[1 => "a"]];
		yield [["a", 1]];
		yield [["a", null]];
	}

	/**
	 * @param mixed[] $values
	 */
	#[DataProvider("invalidEnumValuesProvider")]
	public function testEnumValuesMustBeAStringList(array $values) : void{
		$this->expectException(\InvalidArgumentException::class);
		//wrong on purpose, phpstan rejects a direct call
		new \ReflectionClass(EnumArgument::class)->newInstance("e", $values);
	}

	public function testKindsDescribeTheInputToClients() : void{
		self::assertTrue((new IntegerArgument("n", optional: true))->isOptional());
		self::assertSame(ArgumentKind::INTEGER, (new IntegerArgument("n"))->getKind());
		self::assertSame(ArgumentKind::INTEGER, (new IntegerArgument("n", min: 1))->getKind());
		self::assertSame(ArgumentKind::FLOAT, (new FloatArgument("f"))->getKind());
		self::assertSame(ArgumentKind::PLAYER, (new PlayerArgument("p"))->getKind());
		self::assertSame(ArgumentKind::WORD, (new PlayerArgument("p", suggest: false))->getKind());
		self::assertSame(ArgumentKind::WORD, (new StringArgument("s"))->getKind());
		self::assertSame(ArgumentKind::TEXT, (new StringArgument("s", greedy: true))->getKind());
		$bool = new BooleanArgument("b");
		self::assertSame([ArgumentKind::ENUM, ["true", "false"]], [$bool->getKind(), $bool->getEnumValues()]);
		$enum = new EnumArgument("e", ["b", "a"]);
		self::assertSame([ArgumentKind::ENUM, ["b", "a"]], [$enum->getKind(), $enum->getEnumValues()]);
	}

	/**
	 * @phpstan-param Argument<mixed> $argument
	 */
	private function assertParse(Argument $argument, string $input, mixed $expected) : void{
		try{
			$value = $argument->parse($input, $this->sender());
		}catch(ArgumentParseException){
			self::assertNull($expected, "\"$input\" was rejected");
			return;
		}
		self::assertNotNull($expected, "\"$input\" was accepted");
		self::assertSame($expected, $value);
	}
}
