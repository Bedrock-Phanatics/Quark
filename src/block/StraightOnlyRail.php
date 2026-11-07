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

use quark\block\utils\StraightOnlyRailShape;
use quark\data\runtime\RuntimeDataDescriber;

/**
 * Simple non-curvable rail.
 */
class StraightOnlyRail extends BaseRail{

	private StraightOnlyRailShape $railShape = StraightOnlyRailShape::FLAT_AXIS_Z;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->enum($this->railShape);
	}

	protected function setShapeFromConnections(array $connections) : void{
		$this->railShape = self::searchShape($connections, StraightOnlyRailShape::cases()) ?? throw new \InvalidArgumentException("No rail shape matches these connections");
	}

	protected function getCurrentShapeConnections() : array{
		return $this->railShape->getConnections();
	}

	public function getShape() : StraightOnlyRailShape{ return $this->railShape; }

	/** @return $this */
	public function setShape(StraightOnlyRailShape $shape) : self{
		$this->railShape = $shape;
		return $this;
	}
}
