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
use function yaml_parse;

class ConfigTest extends TestCase{

	/**
	 * @return \Generator|mixed[][]
	 * @phpstan-return \Generator<int, array{string, mixed[]}, void, void>
	 */
	public static function fixYamlIndexesProvider() : \Generator{
		yield ["x: 1\ny: 2\nz: 3\n", [
			"x" => 1,
			"y" => 2,
			"z" => 3
		]];
		yield [" x : 1\n y : 2\n z : 3\n", [
			"x" => 1,
			"y" => 2,
			"z" => 3
		]];
		yield ["parent:\n x: 1\n y: 2\n z: 3\n", [
			"parent" => [
				"x" => 1,
				"y" => 2,
				"z" => 3
			]
		]];
		yield ["yes: notransform", [
			"yes" => "notransform"
		]];
		yield ["on: 1\nyes: true", [ //this would previously have resulted in a key collision
			"on" => 1,
			"yes" => true
		]];
	}

	/**
	 * @dataProvider fixYamlIndexesProvider
	 *
	 * @param mixed[] $expected
	 */
	public function testFixYamlIndexes(string $test, array $expected) : void{
		$fixed = Config::fixYAMLIndexes($test);
		$decoded = yaml_parse($fixed);
		self::assertEquals($expected, $decoded);
	}
}
