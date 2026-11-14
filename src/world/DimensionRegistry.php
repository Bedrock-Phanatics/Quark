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

namespace quark\world;

use pocketmine\network\mcpe\protocol\types\DimensionIds;
use quark\utils\SingletonTrait;

/**
 * Dimensions worlds can be created in. Worlds store the identifier, so a plugin providing a dimension must register
 * it in onLoad(), before worlds using it are loaded.
 */
final class DimensionRegistry{
	use SingletonTrait;

	/** @phpstan-var array<string, Dimension> */
	private array $dimensions = [];

	public function __construct(){
		$this->register(new Dimension(Dimension::OVERWORLD, DimensionIds::OVERWORLD, World::Y_MIN, World::Y_MAX));
		$this->register(new Dimension(Dimension::NETHER, DimensionIds::NETHER, 0, 128, skyLight: false, weather: false, bedWorks: false, respawnAnchorWorks: true));
		$this->register(new Dimension(Dimension::THE_END, DimensionIds::THE_END, 0, 256, skyLight: false, weather: false, bedWorks: false));
	}

	/**
	 * @throws \InvalidArgumentException if the identifier is taken
	 */
	public function register(Dimension $dimension) : void{
		if(isset($this->dimensions[$dimension->identifier])){
			throw new \InvalidArgumentException("Dimension \"$dimension->identifier\" is already registered");
		}
		$this->dimensions[$dimension->identifier] = $dimension;
	}

	public function get(string $identifier) : ?Dimension{
		return $this->dimensions[$identifier] ?? null;
	}

	/**
	 * The dimension of worlds that don't name one.
	 */
	public function getDefault() : Dimension{
		return $this->dimensions[Dimension::OVERWORLD];
	}

	/**
	 * @return Dimension[] in registration order
	 * @phpstan-return array<string, Dimension>
	 */
	public function getAll() : array{
		return $this->dimensions;
	}
}
