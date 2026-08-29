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

use quark\block\utils\redstone\Powerable;
use quark\block\utils\redstone\PowerableTrait;
use quark\block\utils\redstone\RedstoneBlockAccessTrait;
use quark\data\runtime\RuntimeDataDescriber;
use quark\entity\Location;
use quark\entity\object\PrimedTNT;
use quark\entity\projectile\Projectile;
use quark\item\Durable;
use quark\item\enchantment\VanillaEnchantments;
use quark\item\FlintSteel;
use quark\item\Item;
use quark\item\ItemTypeIds;
use pocketmine\math\RayTraceResult;
use pocketmine\math\Vector3;
use quark\player\Player;
use quark\utils\Random;
use quark\world\sound\IgniteSound;
use quark\world\tnt\TntLimiter;
use function cos;
use function sin;
use const M_PI;

class TNT extends Opaque implements Powerable{
	use RedstoneBlockAccessTrait;
	use PowerableTrait;

	private(set) int $activation_delay = 0;
	private(set) int $deactivation_delay = 0;
	private(set) bool $requires_strong_power = false;

	public function isPowered() : bool{ return false; }

	protected function onReceivePower(int $power) : void{
		if($power > 0){ $this->ignite(); }
	}
	protected bool $unstable = false; //TODO: Usage unclear, seems to be a weird hack in vanilla
	protected bool $worksUnderwater = false;

	public function describeBlockItemState(RuntimeDataDescriber $w) : void{
		$w->bool($this->worksUnderwater);
	}

	protected function describeBlockOnlyState(RuntimeDataDescriber $w) : void{
		$w->bool($this->unstable);
	}

	public function isUnstable() : bool{ return $this->unstable; }

	/** @return $this */
	public function setUnstable(bool $unstable) : self{
		$this->unstable = $unstable;
		return $this;
	}

	public function worksUnderwater() : bool{ return $this->worksUnderwater; }

	/** @return $this */
	public function setWorksUnderwater(bool $worksUnderwater) : self{
		$this->worksUnderwater = $worksUnderwater;
		return $this;
	}

	public function onBreak(Item $item, ?Player $player = null, array &$returnedItems = []) : bool{
		if($this->unstable && $this->ignite()){
			return true;
		}
		return parent::onBreak($item, $player, $returnedItems);
	}

	public function onInteract(Item $item, int $face, Vector3 $clickVector, ?Player $player = null, array &$returnedItems = []) : bool{
		if($item->getTypeId() === ItemTypeIds::FIRE_CHARGE){
			if($this->ignite()){
				$item->pop();
			}
			return true;
		}
		if($item instanceof FlintSteel || $item->hasEnchantment(VanillaEnchantments::FIRE_ASPECT())){
			if($this->ignite() && $item instanceof Durable){
				$item->applyDamage(1);
			}
			return true;
		}

		return false;
	}

	public function ignite(int $fuse = 80) : bool{
		$world = $this->position->getWorld();
		if(!TntLimiter::tryIgnite($this->position)){
			return false;
		}
		$world->setBlock($this->position, VanillaBlocks::AIR());

		$mot = (new Random())->nextSignedFloat() * M_PI * 2;

		$tnt = new PrimedTNT(Location::fromObject($this->position->add(0.5, 0, 0.5), $world));
		$tnt->setFuse($fuse);
		$tnt->setWorksUnderwater($this->worksUnderwater);
		$tnt->setMotion(new Vector3(-sin($mot) * 0.02, 0.2, -cos($mot) * 0.02));

		$tnt->spawnToAll();
		$tnt->broadcastSound(new IgniteSound());
		return true;
	}

	public function getFlameEncouragement() : int{
		return 15;
	}

	public function getFlammability() : int{
		return 100;
	}

	public function onIncinerate() : void{
		$this->ignite();
	}

	public function onProjectileHit(Projectile $projectile, RayTraceResult $hitResult) : void{
		if($projectile->isOnFire()){
			$this->ignite();
		}
	}
}
