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

use pocketmine\math\Facing;
use quark\block\utils\RailConnectionInfo;
use quark\block\utils\RailShape;
use quark\data\runtime\RuntimeDataDescriber;

class Rail extends BaseRail{

	private RailShape $railShape = RailShape::FLAT_AXIS_Z;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->enum($this->railShape);
	}

	protected function setShapeFromConnections(array $connections) : void{
		$this->railShape = self::searchShape($connections, RailShape::cases()) ?? throw new \InvalidArgumentException("No rail shape matches these connections");
	}

	protected function getCurrentShapeConnections() : array{
		return $this->railShape->getConnections();
	}

	protected function getPossibleConnectionDirectionsOneConstraint(int $constraint) : array{
		$possible = parent::getPossibleConnectionDirectionsOneConstraint($constraint);

		if(($constraint & RailConnectionInfo::FLAG_ASCEND) === 0){
			foreach([
				Facing::NORTH,
				Facing::SOUTH,
				Facing::WEST,
				Facing::EAST
			] as $d){
				if($constraint !== $d){
					$possible[$d] = true;
				}
			}
		}

		return $possible;
	}

	public function getShape() : RailShape{ return $this->railShape; }

	/** @return $this */
	public function setShape(RailShape $shape) : self{
		$this->railShape = $shape;
		return $this;
	}
}
