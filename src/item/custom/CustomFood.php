<?php

declare(strict_types=1);

namespace quark\item\custom;

use quark\item\Food;
use quark\item\ItemIdentifier;

class CustomFood extends Food{

	public function __construct(ItemIdentifier $identifier, string $name, private readonly int $nutrition, private readonly float $saturation, private readonly bool $alwaysEdible = false, private readonly int $maxStackSize = 64){
		parent::__construct($identifier, $name);
	}

	public function getFoodRestore() : int{ return $this->nutrition; }

	public function getSaturationRestore() : float{ return $this->saturation; }

	public function requiresHunger() : bool{ return !$this->alwaysEdible; }

	public function getMaxStackSize() : int{ return $this->maxStackSize; }
}
