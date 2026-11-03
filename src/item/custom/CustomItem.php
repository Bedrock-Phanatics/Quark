<?php

declare(strict_types=1);

namespace quark\item\custom;

use quark\item\Item;
use quark\item\ItemIdentifier;

class CustomItem extends Item{

	public function __construct(ItemIdentifier $identifier, string $name, private readonly int $maxStackSize = 64){
		parent::__construct($identifier, $name);
	}

	public function getMaxStackSize() : int{
		return $this->maxStackSize;
	}
}
