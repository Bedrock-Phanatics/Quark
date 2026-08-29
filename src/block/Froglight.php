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

use quark\block\utils\FroglightType;
use quark\data\runtime\RuntimeDataDescriber;

final class Froglight extends SimplePillar{

	private FroglightType $froglightType = FroglightType::OCHRE;

	public function describeBlockItemState(RuntimeDataDescriber $w) : void{
		$w->enum($this->froglightType);
	}

	public function getFroglightType() : FroglightType{ return $this->froglightType; }

	/** @return $this */
	public function setFroglightType(FroglightType $froglightType) : self{
		$this->froglightType = $froglightType;
		return $this;
	}

	public function getLightLevel() : int{
		return 15;
	}
}
