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

use pocketmine\color\Color;
use quark\entity\Entity;
use quark\entity\Living;
use quark\event\entity\EntityDamageEvent;
use quark\lang\Translatable;
use function max;

class PoisonEffect extends Effect{
	private bool $fatal;

	public function __construct(Translatable|string $name, Color $color, bool $isBad = false, int $defaultDuration = 600, bool $hasBubbles = true, bool $fatal = false){
		parent::__construct($name, $color, $isBad, $defaultDuration, $hasBubbles);
		$this->fatal = $fatal;
	}

	public function getApplyInterval(EffectInstance $instance) : int{
		return max(1, 25 >> $instance->getAmplifier());
	}

	public function applyEffect(Living $entity, EffectInstance $instance, float $potency = 1.0, ?Entity $source = null) : void{
		if($entity->getHealth() > 1 || $this->fatal){
			$ev = new EntityDamageEvent($entity, EntityDamageEvent::CAUSE_MAGIC, 1);
			$entity->attack($ev);
		}
	}
}
