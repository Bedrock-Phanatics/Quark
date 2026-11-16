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

namespace quark\block\tile;

use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\Tag;
use quark\utils\Utils;

/**
 * Keeps vanilla block entity data that Quark doesn't interpret yet, so it survives a load/save cycle.
 * Only the listed tag names with the expected tag type are kept; anything else is dropped.
 */
trait RetainedTagsTrait{
	/**
	 * @var Tag[]
	 * @phpstan-var list<array{string, Tag}>
	 */
	private array $retainedTags = [];

	/**
	 * @param string[] $tagTypes
	 * @phpstan-param array<string, class-string<Tag>> $tagTypes
	 */
	protected function loadRetainedTags(CompoundTag $nbt, array $tagTypes) : void{
		$this->retainedTags = [];
		foreach(Utils::stringifyKeys($tagTypes) as $name => $type){
			$tag = $nbt->getTag($name);
			if($tag instanceof $type){
				$this->retainedTags[] = [$name, $tag->safeClone()];
			}
		}
	}

	protected function saveRetainedTags(CompoundTag $nbt) : void{
		foreach($this->retainedTags as [$name, $tag]){
			$nbt->setTag($name, $tag->safeClone());
		}
	}
}
