<?php

declare(strict_types=1);

namespace pocketmine\block;

use pocketmine\block\utils\redstone\RedstoneBlockAccessTrait;

use pocketmine\block\Block;
use pocketmine\block\Opaque;
use pocketmine\block\utils\AnyFacing;
use pocketmine\block\utils\PoweredByRedstone;
use pocketmine\block\utils\PoweredByRedstoneTrait;
use pocketmine\data\runtime\RuntimeDataDescriber;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\timings\Timings;
use pocketmine\timings\TimingsHandler;
use pocketmine\world\BlockTransaction;
use pocketmine\block\utils\redstone\Powerable;
use pocketmine\block\utils\redstone\PowerableTrait;
use pocketmine\block\tile\dispenser\Dispenser as DispenserTile;
use function abs;

class Dispenser extends Opaque implements AnyFacing, Powerable, PoweredByRedstone{
	use RedstoneBlockAccessTrait;
	use PowerableTrait;
	use PoweredByRedstoneTrait;

	protected int $facing = Facing::DOWN;
	private(set) int $activation_delay = 0;
	private(set) int $deactivation_delay = 1;
	private(set) bool $requires_strong_power = false;

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->facing($this->facing);
		$w->bool($this->powered);
	}

	public function setFacing(int $facing) : self{
		$this->facing = $facing;
		return $this;
	}

	public function place(BlockTransaction $tx, Item $item, Block $blockReplace, Block $blockClicked, int $face, Vector3 $clickVector, ?Player $player = null) : bool{
		if($player !== null){
			if(abs($player->getPosition()->x - $this->position->x) < 2 && abs($player->getPosition()->z - $this->position->z) < 2){
				$y = $player->getEyePos()->y;

				if($y - $this->position->y > 2){
					$this->facing = Facing::UP;
				}elseif($this->position->y - $y > 0){
					$this->facing = Facing::DOWN;
				}else{
					$this->facing = Facing::opposite($player->getHorizontalFacing());
				}
			}else{
				$this->facing = Facing::opposite($player->getHorizontalFacing());
			}
		}

		return parent::place($tx, $item, $blockReplace, $blockClicked, $face, $clickVector, $player);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($player instanceof Player){
			$tile = $this->position->getWorld()->getTileAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
			if($tile instanceof DispenserTile){
				$player->setCurrentWindow($tile->getInventory());
			}
		}
		return true;
	}

	public function acceptsPowerFromSide(int $side) : bool{
		return $side !== $this->facing;
	}

	public function getFacing() : int{
		return $this->facing;
	}

	protected function onReceivePower(int $power) : void{
		if(!TimingsHandler::isEnabled()){
			$this->handleReceivedPower($power);
			return;
		}
		Timings::$redstoneDispensers->time(fn() => $this->handleReceivedPower($power));
	}

	private function handleReceivedPower(int $power) : void{
		$powered = $power > 0;
		if($powered !== $this->powered){
			$this->powered = $powered;
			$this->position->getWorld()->setBlockAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z, $this, false);
			if($powered){
				$tile = $this->position->getWorld()->getTileAt((int) $this->position->x, (int) $this->position->y, (int) $this->position->z);
				if($tile instanceof DispenserTile){
					$tile->onPower($this->facing);
				}
			}
		}
	}
}