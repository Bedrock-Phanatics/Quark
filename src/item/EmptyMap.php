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
 * TODO: Implement creating a filled map of the surrounding area when used.
 */
class EmptyMap extends Item{
	private bool $locator = false;

	protected function describeState(RuntimeDataDescriber $w) : void{
		$w->bool($this->locator);
	}

	/**
	 * Locator maps show player markers once filled.
	 */
	public function isLocator() : bool{ return $this->locator; }

	/** @return $this */
	public function setLocator(bool $locator) : self{
		$this->locator = $locator;
		return $this;
	}
}
