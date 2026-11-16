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

use quark\block\utils\StructureBlockType;
use quark\data\runtime\RuntimeDataDescriber;
use quark\item\Item;

/**
 * TODO: Implement the structure block UI, saving and loading structures, and redstone triggers.
 */
class StructureBlock extends Opaque{
	protected StructureBlockType $structureBlockType = StructureBlockType::DATA;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->enum($this->structureBlockType);
	}

	public function getStructureBlockType() : StructureBlockType{ return $this->structureBlockType; }

	/** @return $this */
	public function setStructureBlockType(StructureBlockType $type) : self{
		$this->structureBlockType = $type;
		return $this;
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		return [];
	}
}
