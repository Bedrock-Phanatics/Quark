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

namespace quark\command\utils;

use PHPUnit\Framework\TestCase;
use function count;

class CommandStringHelperTest extends TestCase{

	public static function parseQuoteAwareProvider() : \Generator{
		yield [
			'give "steve jobs" apple',
			['give', 'steve jobs', 'apple']
		];
		yield [
			'say \"escaped\"',
			['say', '"escaped"']
		];
		yield [
			'say This message contains \"escaped quotes\", which are ignored',
			['say', 'This', 'message', 'contains', '"escaped', 'quotes",', 'which', 'are', 'ignored']
		];
		yield [
			'say dontspliton"half"wayquotes',
			['say', 'dontspliton"half"wayquotes']
		];
	}

	/**
	 * @dataProvider parseQuoteAwareProvider
	 * @param string[] $expected
	 */
	public function testParseQuoteAware(string $commandLine, array $expected) : void{
		$actual = CommandStringHelper::parseQuoteAware($commandLine);

		self::assertSame($expected, $actual);
	}

	public static function tokenOffsetProvider() : \Generator{
		yield ['say hi', [0, 4]];
		yield ['  say   "a b"   c  ', [2, 8, 16]];
		yield ['say "" x', [0, 7]];
		yield ['say "unterminated words', [0, 4, 18]];
		yield ['say \"q\" "r s" end', [0, 4, 10, 16]];
		yield ['', []];
		yield ["tab\tsep\tx", [0, 4, 8]];
	}

	/**
	 * @param int[] $offsets
	 * @phpstan-param list<int> $offsets
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider("tokenOffsetProvider")]
	public function testTokenOffsetsAgreeWithTokenization(string $commandLine, array $offsets) : void{
		self::assertCount(count($offsets), CommandStringHelper::parseQuoteAware($commandLine));
		foreach($offsets as $index => $offset){
			self::assertSame($offset, CommandStringHelper::tokenOffset($commandLine, $index), "token $index of '$commandLine'");
		}
		self::assertNull(CommandStringHelper::tokenOffset($commandLine, count($offsets)));
	}

	public function testTokenOffsetOfInvalidUtf8IsNull() : void{
		self::assertNull(CommandStringHelper::tokenOffset("say \xff\xfe", 1));
		self::assertSame([], CommandStringHelper::parseQuoteAware("say \xff\xfe"));
	}
}
