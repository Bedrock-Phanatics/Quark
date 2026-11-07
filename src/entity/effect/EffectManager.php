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

namespace quark\entity\effect;

use pocketmine\utils\Limits;
use quark\entity\Living;
use quark\event\entity\EntityEffectAddEvent;
use quark\event\entity\EntityEffectRemoveEvent;
use function count;
use function spl_object_id;

class EffectManager extends EffectCollection{

	public function __construct(
		private Living $entity
	){
		parent::__construct();
	}

	/**
	 * Removes the effect with the specified ID from the mob.
	 */
	public function remove(Effect $effectType) : void{
		$index = spl_object_id($effectType);
		if(isset($this->effects[$index])){
			$effect = $this->effects[$index];
			$ev = new EntityEffectRemoveEvent($this->entity, $effect, $this->durations[$index] <= 0);
			$ev->call();
			if($ev->isCancelled()){
				foreach($this->effectAddHooks as $hook){
					$hook($ev->getEffect(), true);
				}
				return;
			}

			$effect->getType()->remove($this->entity, $effect);
			parent::remove($effectType);
		}
	}

	public function add(EffectInstance $effect) : bool{
		$index = spl_object_id($effect->getType());
		$oldEffect = $this->effects[$index] ?? null;

		$ev = new EntityEffectAddEvent($this->entity, $effect, $oldEffect);
		if(!$this->canAdd($effect)){
			$ev->cancel();
		}

		$ev->call();
		$effect = $ev->getEffect();
		if($ev->isCancelled() || !$this->canAdd($effect)){
			return false;
		}

		if($oldEffect !== null){
			$oldEffect->getType()->remove($this->entity, $oldEffect);
		}

		$effect->getType()->add($this->entity, $effect);

		return parent::add($effect);
	}

	public function tick(int $tickDiff = 1) : bool{
		foreach($this->effects as $index => $instance){
			$remaining = $this->durations[$index] ?? null;
			if($remaining === null){ //removed by an earlier effect this tick
				continue;
			}

			$type = $instance->getType();
			$interval = $type->getApplyInterval($instance);
			if($interval > 0 && $remaining % $interval === 0){
				$type->applyEffect($this->entity, $instance);
			}

			if(!isset($this->durations[$index])){ //cleared by its own tick
				continue;
			}

			$remaining -= $tickDiff;
			if($remaining <= 0){
				//infinite effects wrap around because periodic effects rely on the duration to know when to tick
				$remaining = $instance->isInfinite() ? $remaining + Limits::INT32_MAX : 0;
			}
			$this->durations[$index] = $remaining;
			if($remaining <= 0){
				$this->remove($type);
			}
		}

		return count($this->effects) > 0;
	}
}
