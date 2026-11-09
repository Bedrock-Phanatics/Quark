<?php

declare(strict_types=1);

namespace quark\command\argument;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use quark\command\CommandSender;
use function array_map;
use function str_repeat;

enum EnumTestMode : string{
	case Fast = "fast";
	case SlowMo = "Slow-Mo";
}

enum EnumTestLevel{
	case Low;
	case High;
}

enum EnumTestClash{
	case Foo;
	case FOO;
}

final class EnumAndRelativeTest extends TestCase{
	private function sender() : CommandSender{
		return self::createStub(CommandSender::class);
	}

	public function testNativeEnumsMapCaseInsensitively() : void{
		$mode = new EnumArgument("mode", EnumTestMode::class);
		self::assertSame(EnumTestMode::Fast, $mode->parse("FAST", $this->sender()));
		self::assertSame(EnumTestMode::SlowMo, $mode->parse("slow-MO", $this->sender()));
		self::assertSame(["fast", "slow-mo"], $mode->getChoices());
		self::assertSame("EnumTestMode", $mode->getEnum()->getName());
		self::assertSame(EnumTestMode::class, $mode->getValueType());
		self::assertTrue($mode->matches("Fast"));
		self::assertFalse($mode->matches("fas"));

		$level = new EnumArgument("level", EnumTestLevel::class);
		self::assertSame(EnumTestLevel::High, $level->parse("high", $this->sender()));
		self::assertSame(["low", "high"], $level->getChoices());

		$this->expectException(ArgumentParseException::class);
		$mode->parse("slow", $this->sender());
	}

	public function testMappedEnumsCarryTheirValues() : void{
		$sizes = CommandEnum::fromMap("Size", ["small" => 1, "big" => 100]);
		$argument = new EnumArgument("size", $sizes);
		self::assertSame(100, $argument->parse("BIG", $this->sender()));
		self::assertSame("int", $argument->getValueType());
		self::assertNull($sizes->find("huge"));
		self::assertSame("mixed", CommandEnum::fromMap("Mixed", ["a" => 1, "b" => "x"])->getValueType());
		self::assertSame("string", CommandEnum::fromValues("Words", ["a", "b"])->getValueType());
		self::assertSame($sizes, $argument->getEnum(), "commands share one definition so clients see one enum");
	}

	public function testNumericWordsStayStrings() : void{
		$enum = CommandEnum::fromValues("Numbers", ["0", "1", "easy"]);
		self::assertSame(["0", "1", "easy"], $enum->getValues());
		self::assertSame(["0", "1", "easy"], (new EnumArgument("n", $enum))->getChoices());
		self::assertSame("1", $enum->find("1"));
		self::assertSame(["7"], CommandEnum::fromMap("Seven", ["7" => true])->getValues());
	}

	/**
	 * @phpstan-return \Generator<string, array{\Closure() : mixed}, void, void>
	 */
	public static function invalidEnumProvider() : \Generator{
		yield "empty" => [fn() => CommandEnum::fromValues("E", [])];
		yield "map with no entries" => [fn() => CommandEnum::fromMap("E", [])];
		yield "uppercase key" => [fn() => CommandEnum::fromMap("E", ["A" => 1])];
		yield "key with space" => [fn() => CommandEnum::fromMap("E", ["a b" => 1])];
		yield "null value" => [fn() => CommandEnum::fromMap("E", ["a" => null])];
		yield "bad name" => [fn() => CommandEnum::fromValues("bad name", ["a"])];
		yield "duplicates" => [fn() => CommandEnum::fromValues("E", ["a", "a"])];
		yield "native case collision" => [fn() => CommandEnum::fromCases(EnumTestClash::class)];
	}

	/**
	 * @phpstan-param \Closure() : mixed $factory
	 */
	#[DataProvider("invalidEnumProvider")]
	public function testInvalidEnumsAreRejected(\Closure $factory) : void{
		$this->expectException(\InvalidArgumentException::class);
		$factory();
	}

	/**
	 * @phpstan-return \Generator<string, array{string, ?array{float, bool}}, void, void>
	 */
	public static function relativeFloatProvider() : \Generator{
		yield "integer" => ["5", [5.0, false]];
		yield "negative" => ["-2.5", [-2.5, false]];
		yield "plus" => ["+3", [3.0, false]];
		yield "leading dot" => [".5", [0.5, false]];
		yield "trailing dot" => ["5.", [5.0, false]];
		yield "exponent" => ["1e2", [100.0, false]];
		yield "tilde" => ["~", [0.0, true]];
		yield "relative offset" => ["~-2.5", [-2.5, true]];
		yield "relative plus" => ["~+1", [1.0, true]];
		yield "empty" => ["", null];
		yield "double tilde" => ["~~", null];
		yield "tilde letters" => ["~x", null];
		yield "nan" => ["nan", null];
		yield "inf" => ["inf", null];
		yield "overflow" => ["1e999", null];
		yield "relative overflow" => ["~1e999", null];
		yield "hex" => ["0x10", null];
		yield "double sign" => ["--1", null];
		yield "trailing space" => ["~ ", null];
		yield "trailing newline" => ["1\n", null];
		yield "comma" => ["1,5", null];
		yield "long garbage" => [str_repeat("9", 5000) . "x", null];
	}

	/**
	 * @phpstan-param array{float, bool}|null $expected
	 */
	#[DataProvider("relativeFloatProvider")]
	public function testRelativeFloatsParseAndAgreeWithTheirShapeCheck(string $input, ?array $expected) : void{
		$argument = new RelativeFloatArgument("x");
		//the shape check may accept more than parsing does, never less
		if($expected !== null){
			self::assertTrue(RelativeFloat::isValid($input));
			self::assertTrue($argument->matches($input));
		}
		if($expected === null){
			$this->expectException(ArgumentParseException::class);
			$argument->parse($input, $this->sender());
		}
		$value = $argument->parse($input, $this->sender());
		self::assertSame($expected, [$value->value, $value->relative]);
	}

	public function testRelativeValuesResolveAgainstTheirBase() : void{
		self::assertSame(15.0, RelativeFloat::parse("~5")->resolve(10.0));
		self::assertSame(5.0, RelativeFloat::parse("5")->resolve(10.0));

		$position = new PositionArgument("pos");
		self::assertSame(3, $position->getWidth());
		$value = $position->parseTokens(["x", "~1", "-2", "~"], 1, $this->sender());
		$resolved = $value->resolve(new \pocketmine\math\Vector3(10, 20, 30));
		self::assertSame([11.0, -2.0, 30.0], [$resolved->x, $resolved->y, $resolved->z]);

		$rotation = new RotationArgument("rot");
		self::assertSame(2, $rotation->getWidth());
		$angles = $rotation->parseTokens(["~10", "-5"], 0, $this->sender());
		self::assertSame([100.0, -5.0], [$angles->yaw->resolve(90.0), $angles->pitch->resolve(0.0)]);

		$this->expectException(ArgumentParseException::class);
		$position->parseTokens(["1", "2", "nope"], 0, $this->sender());
	}

	public function testDurationsAndNumberFreeStrings() : void{
		$duration = new DurationArgument("t", 1.0, 86400.0);
		foreach([["30", 30.0], ["1m", 60.0], ["1.5H", 5400.0], ["2500ms", 2.5]] as [$input, $expected]){
			self::assertSame($expected, $duration->parse($input, $this->sender()));
		}
		foreach(["0.5", "25h", "-1", "1e3", "1 m", "", str_repeat("1", 40)] as $input){
			try{
				$duration->parse($input, $this->sender());
				self::fail("$input should be rejected");
			}catch(ArgumentParseException){
				$this->addToAssertionCount(1);
			}
		}

		$name = new StringArgument("name", numbers: false);
		self::assertFalse($name->allowsNumbers());
		self::assertSame([false, false, true, true], array_map(fn(string $t) => $name->matches($t), ["12", "-1.5", "tp", "~"]));
		self::assertTrue((new StringArgument("any"))->matches("12"));
	}

	public function testPlayerArgumentOptions() : void{
		$sender = self::createStub(CommandSender::class);
		$server = self::createStub(\quark\Server::class);
		$sender->method("getServer")->willReturn($server);
		$argument = new PlayerArgument("p");
		$this->expectException(ArgumentParseException::class);
		$argument->parse("@a", $sender);
	}

	public function testMappedArgumentsUseTheirMapperAndSuggestions() : void{
		$suggestions = CommandEnum::fromValues("Ids", ["one", "two"]);
		$argument = new MappedArgument("thing", static fn(string $name) : ?\stdClass => $name === "one" ? new \stdClass() : null, \stdClass::class, suggestions: $suggestions);
		self::assertInstanceOf(\stdClass::class, $argument->parse("one", $this->sender()));
		self::assertSame(ArgumentKind::ENUM, $argument->getKind());
		self::assertSame($suggestions, $argument->getEnum());
		self::assertSame(\stdClass::class, $argument->getValueType());
		self::assertNull($argument->getChoices(), "the mapper may accept more than the suggestions");
		$this->expectException(ArgumentParseException::class);
		$argument->parse("two", $this->sender());
	}
}
