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

namespace quark\item;

use quark\data\runtime\RuntimeDataDescriber;

/**
 * The map's pixels live in separate map data keyed by the map_uuid tag, which is kept with the item's other NBT.
 * The display type (explorer, treasure, locked...) is stored as the vanilla item meta and kept verbatim.
 * TODO: Implement map data storage, sending map pixels to clients, and the cartography table.
 */
class FilledMap extends Item{
	public const MAX_MAP_TYPE = 0x7fff;

	private int $mapType = 0;

	protected function describeState(RuntimeDataDescriber $w) : void{
		$w->boundedIntAuto(0, self::MAX_MAP_TYPE, $this->mapType);
	}

	public function getMapType() : int{ return $this->mapType; }

	/** @return $this */
	public function setMapType(int $mapType) : self{
		if($mapType < 0 || $mapType > self::MAX_MAP_TYPE){
			throw new \InvalidArgumentException("Map type must be in range 0 ... " . self::MAX_MAP_TYPE);
		}
		$this->mapType = $mapType;
		return $this;
	}
}
