<?php

declare(strict_types=1);

namespace pocketmine\block\utils\redstone;

use pocketmine\block\utils\redstone\RedstoneBlockAccessTrait;

use pocketmine\block\RedstoneLamp as VanillaRedstoneLamp;
use pocketmine\block\utils\redstone\Powerable;
use pocketmine\block\utils\redstone\PowerableTrait;

trait RedstoneLampBehavior{
	use RedstoneBlockAccessTrait;
	use PowerableTrait;

	private(set) int $activation_delay = 0;
	private(set) int $deactivation_delay = 2;
	private(set) bool $requires_strong_power = true;

	protected function onReceivePower(int $power) : void{
		$powered = $power > 0;
		if($powered !== $this->powered){
			$this->position->getWorld()->setBlockAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z, $this->setPowered($powered), false);
		}
	}
}