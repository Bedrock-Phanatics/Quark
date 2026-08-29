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

class RandomTest extends TestCase{

	public function testNextSignedIntReturnsSignedInts() : void{
		//use a known seed which should definitely produce negatives
		$random = new Random(0);
		$negatives = false;

		for($i = 0; $i < 100; ++$i){
			if($random->nextSignedInt() < 0){
				$negatives = true;
				break;
			}
		}
		self::assertTrue($negatives);
	}
}
