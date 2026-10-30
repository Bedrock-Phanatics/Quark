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

namespace quark\world;

use PHPUnit\Framework\TestCase;
use function str_repeat;

class WorldNameValidationTest extends TestCase{

	/**
	 * @dataProvider validWorldNamesProvider
	 */
	public function testValidWorldNames(string $name) : void{
		self::assertTrue(WorldManager::isValidWorldName($name), "Expected valid world name: $name");
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function validWorldNamesProvider() : array{
		return [
			"simple" => ["world"],
			"with_underscore" => ["my_world"],
			"with_hyphen" => ["test-world"],
			"with_dot" => ["world.1"],
			"with_spaces" => ["my cool world"],
			"single_character" => ["a"],
			"single_number" => ["1"],
			"maximum_length_64" => [str_repeat("a", 64)],
		];
	}

	/**
	 * @dataProvider invalidWorldNamesProvider
	 */
	public function testInvalidWorldNames(string $name, string $reason) : void{
		self::assertFalse(WorldManager::isValidWorldName($name), "Expected invalid world name ($reason): $name");
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function invalidWorldNamesProvider() : array{
		return [
			"empty" => ["", "empty string"],
			"over_64_chars" => [str_repeat("a", 65), "exceeds 64 characters"],
			"forward_slash" => ["world/sub", "contains path separator /"],
			"back_slash" => ["world\\sub", "contains path separator \\"],
			"colon" => ["world:sub", "contains forbidden character :"],
			"asterisk" => ["world*", "contains forbidden character *"],
			"question_mark" => ["world?", "contains forbidden character ?"],
			"double_quote" => ['world"name', 'contains forbidden character "'],
			"less_than" => ["world<1", "contains forbidden character <"],
			"greater_than" => ["world>1", "contains forbidden character >"],
			"pipe" => ["world|pipe", "contains forbidden character |"],
			"null_byte" => ["world\0", "contains null byte"],
			"newline" => ["world\n", "contains newline"],
			"leading_dot_single" => [".", "single dot"],
			"leading_dot_double" => ["..", "double dot (traversal)"],
			"leading_dot_hidden" => [".hidden", "starts with dot"],
			"trailing_dot" => ["world.", "ends with dot"],
			"trailing_double_dot" => ["world..", "ends with dots"],
			"trailing_space" => ["world ", "ends with space"],
			"traversal_up" => ["../world", "directory traversal"],
			"traversal_middle" => ["foo/../bar", "directory traversal"],
			"device_CON" => ["CON", "Windows reserved device name"],
			"device_con_lower" => ["con", "Windows reserved device name lowercase"],
			"device_PRN" => ["PRN", "Windows reserved device name"],
			"device_prn_lower" => ["prn", "Windows reserved device name lowercase"],
			"device_AUX" => ["AUX", "Windows reserved device name"],
			"device_aux_lower" => ["aux", "Windows reserved device name lowercase"],
			"device_NUL" => ["NUL", "Windows reserved device name"],
			"device_nul_lower" => ["nul", "Windows reserved device name lowercase"],
			"device_COM1" => ["COM1", "Windows reserved device name"],
			"device_com9" => ["com9", "Windows reserved device name lowercase"],
			"device_LPT1" => ["LPT1", "Windows reserved device name"],
			"device_lpt9" => ["lpt9", "Windows reserved device name lowercase"],
			"device_with_ext_con" => ["con.txt", "Windows reserved device name with extension"],
			"device_with_ext_aux" => ["AUX.dat", "Windows reserved device name with extension"],
			"device_with_ext_nul" => ["nul.world", "Windows reserved device name with extension"],
		];
	}
}
