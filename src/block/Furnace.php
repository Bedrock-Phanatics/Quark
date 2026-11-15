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

namespace quark\block;

use quark\block\inventory\window\FurnaceInventoryWindow;
use quark\block\tile\Furnace as TileFurnace;
use quark\block\utils\Container;
use quark\block\utils\ContainerTrait;
use quark\block\utils\FacesOppositePlacingPlayerTrait;
use quark\block\utils\HorizontalFacing;
use quark\block\utils\Lightable;
use quark\block\utils\LightableTrait;
use quark\crafting\FurnaceType;
use quark\data\runtime\RuntimeDataDescriber;
use quark\inventory\Inventory;
use quark\math\Facing;
use quark\player\InventoryWindow;
use quark\player\Player;
use quark\world\Position;
use function mt_rand;

class Furnace extends Opaque implements Container, Lightable, HorizontalFacing{
	use ContainerTrait;
	use FacesOppositePlacingPlayerTrait;
	use LightableTrait;

	public const int DEFAULT_FACING = Facing::SOUTH;

	protected FurnaceType $furnaceType;

	public function __construct(BlockIdentifier $idInfo, string $name, BlockTypeInfo $typeInfo, FurnaceType $furnaceType){
		$this->furnaceType = $furnaceType;
		parent::__construct($idInfo, $name, $typeInfo);
	}

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->horizontalFacing($this->facing);
		$w->bool($this->lit);
	}

	public function getFurnaceType() : FurnaceType{
		return $this->furnaceType;
	}

	public function getLightLevel() : int{
		return $this->lit ? 13 : 0;
	}

	public function onScheduledUpdate() : void{
		$world = $this->position->getWorld();
		$furnace = $world->getTile($this->position);
		if($furnace instanceof TileFurnace && $furnace->onUpdate()){
			if(mt_rand(1, 60) === 1){ //in vanilla this is between 1 and 5 seconds; try to average about 3
				$world->addSound($this->position, $furnace->getFurnaceType()->getCookSound());
			}
			$world->scheduleDelayedBlockUpdate($this->position, 1); //TODO: check this
		}
	}

	protected function newMenu(Player $player, Inventory $inventory, Position $position) : InventoryWindow{
		return new FurnaceInventoryWindow($player, $inventory, $position, $this->furnaceType);
	}
}
