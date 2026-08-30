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

namespace quark\event\block;

use pocketmine\math\Vector3;
use quark\block\Piston;
use quark\block\tile\piston\PistonMovement;
use quark\event\Cancellable;
use quark\event\CancellableTrait;
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
