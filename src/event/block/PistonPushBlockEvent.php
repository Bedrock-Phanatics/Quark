<?php

declare(strict_types=1);

namespace pocketmine\event\block;

use pocketmine\block\Block;
use pocketmine\event\block\BlockEvent;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\math\Vector3;
use pocketmine\block\Piston;
use pocketmine\block\tile\piston\PistonMovement;

final class PistonPushBlockEvent extends BlockEvent implements Cancellable{
	use CancellableTrait;

	/**
	 * @param Piston $piston
	 * @param Vector3 $arm_pos
	 * @param list<PistonMovement> $movements
	 */
	readonly public Piston $piston;
	readonly public Vector3 $armPosition;
	/** @var list<PistonMovement> */
	readonly public array $movements;

	/** @param list<PistonMovement> $movements */
	public function __construct(Piston $piston, Vector3 $armPosition, array $movements){
		$this->piston = clone $piston;
		$this->armPosition = clone $armPosition;
		$this->movements = array_map(
			static fn(PistonMovement $movement) => new PistonMovement(clone $movement->block, clone $movement->from, clone $movement->to),
			$movements
		);
		parent::__construct($this->piston);
	}
}