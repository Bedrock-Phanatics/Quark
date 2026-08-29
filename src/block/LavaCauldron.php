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

use quark\block\tile\Cauldron as TileCauldron;
use quark\entity\Entity;
use quark\event\entity\EntityCombustByBlockEvent;
use quark\event\entity\EntityDamageByBlockEvent;
use quark\event\entity\EntityDamageEvent;
use quark\item\Item;
use quark\item\ItemTypeIds;
use quark\item\VanillaItems;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\world\sound\CauldronEmptyLavaSound;
use quark\world\sound\CauldronFillLavaSound;
use quark\world\sound\Sound;
use function assert;

final class LavaCauldron extends FillableCauldron{

	public function writeStateToWorld() : void{
		parent::writeStateToWorld();
		$tile = $this->position->getWorld()->getTile($this->position);
		assert($tile instanceof TileCauldron);

		$tile->setCustomWaterColor(null);
		$tile->setPotionItem(null);
	}

	public function getLightLevel() : int{
		return 15;
	}

	public function getFillSound() : Sound{
		return new CauldronFillLavaSound();
	}

	public function getEmptySound() : Sound{
		return new CauldronEmptyLavaSound();
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		match($item->getTypeId()){
			ItemTypeIds::BUCKET => $this->removeFillLevels(self::MAX_FILL_LEVEL, $item, VanillaItems::LAVA_BUCKET(), $returnedItems),
			ItemTypeIds::POWDER_SNOW_BUCKET, ItemTypeIds::WATER_BUCKET => $this->mix($item, VanillaItems::BUCKET(), $returnedItems),
			ItemTypeIds::LINGERING_POTION, ItemTypeIds::POTION, ItemTypeIds::SPLASH_POTION => $this->mix($item, VanillaItems::GLASS_BOTTLE(), $returnedItems),
			default => null
		};
		return true;
	}

	public function hasEntityCollision() : bool{ return true; }

	public function onEntityInside(Entity $entity) : bool{
		$ev = new EntityDamageByBlockEvent($this, $entity, EntityDamageEvent::CAUSE_LAVA, 4);
		$entity->attack($ev);

		$ev = new EntityCombustByBlockEvent($this, $entity, 8);
		$ev->call();
		if(!$ev->isCancelled()){
			$entity->setOnFire($ev->getDuration());
		}

		return true;
	}
}
