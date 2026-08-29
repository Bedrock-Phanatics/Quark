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

namespace quark\inventory;

use quark\item\Item;
use quark\lang\Translatable;
use function strlen;

/**
 * Info for an item group in the creative inventory menu.
 */
final class CreativeGroup{
	/**
	 * @param Translatable|string $name Tooltip shown to the player on hover
	 * @param Item                $icon Item shown when the group is collapsed
	 */
	public function __construct(
		private readonly Translatable|string $name,
		private readonly Item $icon
	){
		$nameLength = $name instanceof Translatable ? strlen($name->getText()) : strlen($name);
		if($nameLength === 0){
			throw new \InvalidArgumentException("Creative group name cannot be empty");
		}
	}

	public function getName() : Translatable|string{ return $this->name; }

	public function getIcon() : Item{ return clone $this->icon; }
}
