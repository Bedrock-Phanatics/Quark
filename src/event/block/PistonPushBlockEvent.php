<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\event\block;

use pocketmine\block\Piston;
use pocketmine\block\tile\piston\PistonMovement;
use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\math\Vector3;
use function array_map;

final class PistonPushBlockEvent extends BlockEvent implements Cancellable{
	use CancellableTrait;

	/**
	 * @param Piston               $piston
	 * @param Vector3              $arm_pos
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
