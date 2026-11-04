<?php

declare(strict_types=1);

namespace quark\block\custom;

use quark\block\BlockIdentifier;
use quark\block\BlockTypeInfo;
use quark\block\Crops;
use quark\item\Item;
use function mt_rand;

/**
 * Farmland crop whose block item is its seed. Mature crops also drop a clone of the produce.
 */
class CustomCrop extends Crops{
	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo, private readonly ?Item $produce = null){
		parent::__construct($idInfo, $name, $typeInfo);
	}

	public function getDropsForCompatibleTool(Item $item) : array{
		$drops = [$this->asItem()];
		if($this->age >= self::MAX_AGE){
			$drops[0]->setCount(mt_rand(1, 3));
			if($this->produce !== null){
				$drops[] = clone $this->produce;
			}
		}
		return $drops;
	}
}
