<?php

declare(strict_types=1);

namespace pocketmine\block\tile\dispenser;

use Closure;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\world\Position;
use pocketmine\world\World;

class EntityDispensableItem implements DispensableItem{

	/**
	 * @param Closure(Location, Item, ?Player) : Entity $entity_creator
	 */
	public function __construct(
		private Closure $entity_creator
	){}

	public function dispense(Position $pos, Inventory $inventory, int $slot, Vector3 $side_pos, int $facing, ?Player $player = null) : bool{
		$world = $pos->getWorld();
		$item = $inventory->getItem($slot);
		$item_removed = $item->pop();
		$inventory->setItem($slot, $item);

		$entity = ($this->entity_creator)(Location::fromObject($side_pos->add(0.5, 0.5, 0.5), $world), $item_removed, $player);
		$this->onEntityCreate($entity, $side_pos, $world, $facing, $item_removed, $player);
		$entity->spawnToAll();
		return true;
	}

	protected function onEntityCreate(Entity $entity, Vector3 $side_pos, World $world, int $facing, Item $item, ?Player $player = null) : void{
	}
}