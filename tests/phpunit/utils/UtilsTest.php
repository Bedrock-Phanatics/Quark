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

namespace quark\utils;

use PHPUnit\Framework\TestCase;
use quark\utils\fixtures\TestAbstractClass;
use quark\utils\fixtures\TestInstantiableClass;
use quark\utils\fixtures\TestInterface;
use quark\utils\fixtures\TestSubclassOfInstantiableClass;
use quark\utils\fixtures\TestTrait;
use function base64_encode;
use function define;
use function defined;
use function min;
use function str_repeat;
use function strlen;
use function substr;
use const PHP_INT_MAX;

class UtilsTest extends TestCase{

	public function setUp() : void{
		if(!defined('quark\PATH')){
			define('quark\PATH', 'dummy');
		}
	}

	public function testPacketPreviewsBoundSizeAndReportOmittedBytes() : void{
		foreach([0, 1, 1024, 1025, 8 * 1024 * 1024] as $size){
			$buffer = str_repeat("\x00", $size);
			$preview = Utils::printablePacketBuffer($buffer);
			$expected = "$size bytes, base64: " . base64_encode(str_repeat("\x00", min($size, 1024)));
			if($size > 1024){ $expected .= " ... (" . ($size - 1024) . " bytes not shown)"; }
			self::assertSame($expected, $preview);
			self::assertLessThan(1500, strlen($preview));
			self::assertSame("0 bytes, base64: ", Utils::printablePacketBuffer($buffer, $size));
		}
		self::assertSame("3 bytes, base64: AP9B", Utils::printablePacketBuffer("prefix\x00\xffA", 6));
		foreach([-1, 7, PHP_INT_MAX] as $offset){
			try{ Utils::printablePacketBuffer("buffer", $offset); self::fail("Invalid preview offset accepted"); }catch(\InvalidArgumentException){}
		}
	}

	public function testTraceArgumentFormattingKeepsExistingOutput() : void{
		$buffer = str_repeat("A\x00B\t\xffC", 200);
		foreach([-1, 0, 1, 80, 1201] as $length){
			$expected = substr(str_repeat("A.B..C", 200), 0, $length);
			self::assertSame(["#0 (): probe(string[1200] $expected)"], Utils::printableTrace([["function" => "probe", "args" => [$buffer]]], $length));
		}
		$buffer = str_repeat("\x00", 8 * 1024 * 1024);
		self::assertSame(["#0 (): probe(string[8388608] " . str_repeat(".", 80) . ")"], Utils::printableTrace([["function" => "probe", "args" => [$buffer]]]));
	}

	/**
	 * @return string[][]
	 * @phpstan-return list<array{string}>
	 */
	public static function parseDocCommentNewlineProvider() : array{
		return [
			["\t/**\r\n\t * @param PlayerJoinEvent \$event\r\n\t * @priority HIGHEST\r\n\t * @notHandler\r\n\t */"],
			["\t/**\n\t * @param PlayerJoinEvent \$event\n\t * @priority HIGHEST\n\t * @notHandler\n\t */"],
			["\t/**\r\t * @param PlayerJoinEvent \$event\r\t * @priority HIGHEST\r\t * @notHandler\r\t */"]
		];
	}

	/**
	 * @dataProvider parseDocCommentNewlineProvider
	 */
	public function testParseDocCommentNewlines(string $docComment) : void{
		$tags = Utils::parseDocComment($docComment);

		self::assertArrayHasKey("notHandler", $tags);
		self::assertEquals("", $tags["notHandler"]);
		self::assertArrayHasKey("priority", $tags);
		self::assertEquals("HIGHEST", $tags["priority"]);
	}

	/**
	 * @return string[][]
	 * @phpstan-return list<array{string}>
	 */
	public static function parseDocCommentOneLineProvider() : array{
		return [
			["/** @ignoreCancelled true dummy */"],
			["/**@ignoreCancelled true dummy*/"],
			["/** @ignoreCancelled    true dummy */"]
		];
	}

	/**
	 * @dataProvider parseDocCommentOneLineProvider
	 */
	public function testParseOneLineDocComment(string $comment) : void{
		$tags = Utils::parseDocComment($comment);
		self::assertArrayHasKey("ignoreCancelled", $tags);
		self::assertEquals("true dummy", $tags["ignoreCancelled"]);
	}

	public function testParseEmptyDocComment() : void{
		$tags = Utils::parseDocComment("");
		self::assertCount(0, $tags);
	}

	public function testParseDocCommentWithTagsContainingHyphens() : void{
		$tags = Utils::parseDocComment("/** @phpstan-return list<string> */");
		self::assertArrayHasKey("phpstan-return", $tags);
		self::assertEquals("list<string>", $tags["phpstan-return"]);
	}

	public function testNamespacedNiceClosureName() : void{
		//be careful with this test. The closure has to be declared on the same line as the assertion.
		self::assertSame('closure@' . Filesystem::cleanPath(__FILE__) . '#L' . __LINE__, Utils::getNiceClosureName(function() : void{}));
	}

	/**
	 * @return string[][]
	 * @return list<array{class-string, class-string}>
	 */
	public static function validInstanceProvider() : array{
		return [
			//direct instance / implement / extend
			[TestInstantiableClass::class, TestInstantiableClass::class],
			[TestInstantiableClass::class, TestAbstractClass::class],
			[TestInstantiableClass::class, TestInterface::class],

			//inherited
			[TestSubclassOfInstantiableClass::class, TestInstantiableClass::class],
			[TestSubclassOfInstantiableClass::class, TestAbstractClass::class],
			[TestSubclassOfInstantiableClass::class, TestInterface::class]
		];
	}

	/**
	 * @dataProvider validInstanceProvider
	 * @doesNotPerformAssertions
	 * @phpstan-param class-string $className
	 * @phpstan-param class-string $baseName
	 */
	public function testValidInstanceWithValidCombinations(string $className, string $baseName) : void{
		Utils::testValidInstance($className, $baseName);
	}

	/**
	 * @return string[][]
	 * @return list<array{string, string}>
	 */
	public static function validInstanceInvalidCombinationsProvider() : array{
		return [
			["iDontExist abc", TestInstantiableClass::class],
			[TestInstantiableClass::class, "iDon'tExist abc"],
			["iDontExist", "iAlsoDontExist"],
			[TestInstantiableClass::class, TestTrait::class],
			[TestTrait::class, TestTrait::class],
			[TestAbstractClass::class, TestAbstractClass::class],
			[TestInterface::class, TestInterface::class],
			[TestInstantiableClass::class, TestSubclassOfInstantiableClass::class]
		];
	}

	/**
	 * @dataProvider validInstanceInvalidCombinationsProvider
	 */
	public function testValidInstanceInvalidParameters(string $className, string $baseName) : void{
		$this->expectException(\InvalidArgumentException::class);
		Utils::testValidInstance($className, $baseName); //@phpstan-ignore-line
	}
}
