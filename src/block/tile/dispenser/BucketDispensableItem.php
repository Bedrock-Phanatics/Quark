<?php

declare(strict_types=1);

namespace pocketmine\block\tile\dispenser;

use pocketmine\block\Liquid;
use pocketmine\block\VanillaBlocks;
use pocketmine\inventory\Inventory;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\Position;

class BucketDispensableItem extends DropDispensableItem{

	public function dispense(Position $pos, Inventory $inventory, int $slot, Vector3 $side_pos, int $facing, ?Player $player = null) : bool{
		$world = $pos->getWorld();
		$block = $world->getBlockAt((int) $side_pos->x, (int) $side_pos->y, (int) $side_pos->z);
		if($block instanceof Liquid && $block->isSource()){
			$world->setBlockAt((int) $side_pos->x, (int) $side_pos->y, (int) $side_pos->z, VanillaBlocks::AIR());
			$item = $inventory->getItem($slot);
			$item->pop();
			$inventory->setItem($slot, $item);
			foreach($inventory->addItem($block->getFlowingForm()->asItem()) as $drop){
				$world->dropItem($side_pos->add(0.3, 0.3, 0.3), $drop);
			}
			return true;
		}

		return parent::dispense($pos, $inventory, $slot, $side_pos, $facing, $player);
	}
}