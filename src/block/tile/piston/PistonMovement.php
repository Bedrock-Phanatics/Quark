<?php

declare(strict_types=1);

namespace pocketmine\block\tile\piston;

use pocketmine\block\Block;
use pocketmine\math\Vector3;

final class PistonMovement{

	public function __construct(
		readonly public Block $block,
		readonly public Vector3 $from,
		readonly public Vector3 $to
	){}
}