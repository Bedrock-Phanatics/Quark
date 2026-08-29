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

namespace quark\world\biome\model;

/**
 * Model for loading biome definition entries data from JSON.
 */
final class BiomeDefinitionEntryData{
	/** @required */
	public int $id;

	/** @required */
	public float $temperature;

	/** @required */
	public float $downfall;

	/** @required */
	public float $foliageSnow;

	/** @required */
	public float $depth;

	/** @required */
	public float $scale;

	/** @required */
	public ColorData $mapWaterColour;

	/** @required */
	public bool $rain;

	/**
	 * @required
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	public array $tags;
}
