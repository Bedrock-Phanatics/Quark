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

namespace quark\network\mcpe\protocol\types\recipe;

final class TagItemDescriptor implements ItemDescriptor{

	//used to indicate that the item has multiple selectable variants
	private const DEFAULT_META = 32767;

	public function __construct(
		private string $tag,
		private int $meta = self::DEFAULT_META
	){}

	public function getDescriptorType() : ItemDescriptorType{
		return ItemDescriptorType::TAG;
	}

	public function getTag() : string{ return $this->tag; }

	public function getMeta() : int{ return $this->meta; }
}
