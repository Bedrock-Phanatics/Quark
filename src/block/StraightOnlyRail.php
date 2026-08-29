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

namespace quark\block;

use quark\block\utils\RailConnectionInfo;
use quark\data\bedrock\block\BlockLegacyMetadata;
use quark\data\runtime\RuntimeDataDescriber;
use function array_keys;
use function implode;

/**
 * Simple non-curvable rail.
 */
class StraightOnlyRail extends BaseRail{

	private int $railShape = BlockLegacyMetadata::RAIL_STRAIGHT_NORTH_SOUTH;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->straightOnlyRailShape($this->railShape);
	}

	protected function setShapeFromConnections(array $connections) : void{
		$railShape = self::searchState($connections, RailConnectionInfo::CONNECTIONS);
		if($railShape === null){
			throw new \InvalidArgumentException("No rail shape matches these connections");
		}
		$this->railShape = $railShape;
	}

	protected function getCurrentShapeConnections() : array{
		return RailConnectionInfo::CONNECTIONS[$this->railShape];
	}

	public function getShape() : int{ return $this->railShape; }

	/** @return $this */
	public function setShape(int $shape) : self{
		if(!isset(RailConnectionInfo::CONNECTIONS[$shape])){
			throw new \InvalidArgumentException("Invalid rail shape, must be one of " . implode(", ", array_keys(RailConnectionInfo::CONNECTIONS)));
		}
		$this->railShape = $shape;
		return $this;

	}
}
