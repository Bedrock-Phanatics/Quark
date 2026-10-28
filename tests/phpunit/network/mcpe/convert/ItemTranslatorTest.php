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

namespace quark\network\mcpe\convert;

use PHPUnit\Framework\TestCase;
use quark\block\RuntimeBlockStateRegistry;
use quark\data\bedrock\item\ItemTypeSerializeException;

final class ItemTranslatorTest extends TestCase{

	public function testCachedBlockItemIdsMatchSerializing() : void{
		$translator = TypeConverter::getInstance()->getItemTranslator();
		$serialize = new \ReflectionMethod(ItemTranslator::class, "serializeNetworkId");
		$checked = 0;
		foreach(RuntimeBlockStateRegistry::getInstance()->getAllKnownStates() as $state){
			$item = $state->asItem();
			if($item->isNull()){
				continue;
			}
			try{
				$expected = $serialize->invoke($translator, $item);
			}catch(ItemTypeSerializeException){
				continue;
			}
			self::assertSame($expected, $translator->toNetworkId($item));
			self::assertSame($expected, $translator->toNetworkId($item));
			++$checked;
		}
		self::assertGreaterThan(1000, $checked);
	}
}
