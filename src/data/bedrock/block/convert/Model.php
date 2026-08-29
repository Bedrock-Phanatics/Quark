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

namespace quark\data\bedrock\block\convert;

use quark\block\Block;
use quark\data\bedrock\block\convert\property\Property;

/**
 * This class works around a limitation in PHPStan.
 * Ideally, we'd just have a function that accepted ($block, $id, $properties) all together and just have the template
 * type inferred from $block alone.
 * However, there's no way to tell PHPStan to ignore $properties for inference, so we're stuck with this hack.
 *
 * @phpstan-template TBlock of Block
 */
final class Model{

	/**
	 * @var Property[]
	 * @phpstan-var list<Property<contravariant TBlock>>
	 */
	private array $properties = [];

	/**
	 * @phpstan-param TBlock $block
	 */
	private function __construct(
		private Block $block,
		private string $id
	){}

	/** @phpstan-return TBlock */
	public function getBlock() : Block{ return $this->block; }

	public function getId() : string{ return $this->id; }

	/**
	 * @return Property[]
	 * @phpstan-return list<Property<contravariant TBlock>>
	 */
	public function getProperties() : array{ return $this->properties; }

	/**
	 * @phpstan-template TBlock_ of Block
	 * @phpstan-param TBlock_ $block
	 * @phpstan-return self<TBlock_>
	 */
	public static function create(Block $block, string $id) : self{
		return new self($block, $id);
	}

	/**
	 * @param Property[] $properties
	 * @phpstan-param list<Property<contravariant TBlock>> $properties
	 * @phpstan-return $this
	 */
	public function properties(array $properties) : self{
		$this->properties = $properties;
		return $this;
	}
}
