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

namespace quark\crafting\json;

use function count;

final class ItemStackData implements \JsonSerializable{

	/** @required */
	public string $name;

	public int $count;
	public string $block_states;
	public int $meta;
	public string $nbt;
	/** @var string[] */
	public array $can_place_on;
	/** @var string[] */
	public array $can_destroy;

	public function __construct(string $name){
		$this->name = $name;
	}

	/**
	 * @return mixed[]|string
	 */
	public function jsonSerialize() : array|string{
		$result = (array) $this;
		if(count($result) === 1 && isset($result["name"])){
			return $this->name;
		}
		return $result;
	}
}
