<?php

declare(strict_types=1);

namespace pocketmine\event\block;

use pocketmine\block\Block;
use pocketmine\event\block\BlockEvent;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\block\Piston;

final class PistonPullBlockEvent extends BlockEvent implements Cancellable{
	use CancellableTrait;

	public function __construct(
		readonly public Piston $piston,
		Block $block
	){
		parent::__construct($block);
	}
}