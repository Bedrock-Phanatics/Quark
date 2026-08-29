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

class SuspiciousStew extends Food{

	private SuspiciousStewType $suspiciousStewType = SuspiciousStewType::POPPY;

	protected function describeState(RuntimeDataDescriber $w) : void{
		$w->enum($this->suspiciousStewType);
	}

	public function getType() : SuspiciousStewType{ return $this->suspiciousStewType; }

	/**
	 * @return $this
	 */
	public function setType(SuspiciousStewType $type) : self{
		$this->suspiciousStewType = $type;
		return $this;
	}

	public function getMaxStackSize() : int{
		return 1;
	}

	public function requiresHunger() : bool{
		return false;
	}

	public function getFoodRestore() : int{
		return 6;
	}

	public function getSaturationRestore() : float{
		return 7.2;
	}

	public function getAdditionalEffects() : array{
		return $this->suspiciousStewType->getEffects();
	}

	public function getResidue() : Item{
		return VanillaItems::BOWL();
	}
}
