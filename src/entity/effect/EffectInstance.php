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
use quark\color\Color;

/**
 * Immutable description of an effect. The ticks left on an active effect are tracked by the {@see EffectCollection}
 * holding it, so one instance can safely be applied to any number of entities.
 */
class EffectInstance{
	private readonly int $duration;
	private readonly int $amplifier;
	private readonly Color $color;

	/**
	 * @param int|null $duration Passing null will use the effect type's default duration
	 */
	public function __construct(
		private readonly Effect $effectType,
		?int $duration = null,
		int $amplifier = 0,
		private readonly bool $visible = true,
		private readonly bool $ambient = false,
		?Color $overrideColor = null,
		private readonly bool $infinite = false
	){
		$duration ??= $effectType->getDefaultDuration();
		if($duration < 0 || $duration > Limits::INT32_MAX){
			throw new \InvalidArgumentException("Effect duration must be in range 0 - " . Limits::INT32_MAX . ", got $duration");
		}
		if($amplifier < 0 || $amplifier > 255){
			throw new \InvalidArgumentException("Amplifier must be in range 0 - 255, got $amplifier");
		}
		$this->duration = $duration;
		$this->amplifier = $amplifier;
		$this->color = $overrideColor ?? $effectType->getColor();
	}

	public function getType() : Effect{
		return $this->effectType;
	}

	/**
	 * Returns the number of ticks this effect lasts when applied. For the ticks left on an active effect, see
	 * {@see EffectCollection::getRemainingDuration()}.
	 */
	public function getDuration() : int{
		return $this->duration;
	}

	/**
	 * Returns a copy of this instance with a different duration.
	 *
	 * @throws \InvalidArgumentException
	 */
	public function withDuration(int $duration) : EffectInstance{
		return new EffectInstance($this->effectType, $duration, $this->amplifier, $this->visible, $this->ambient, $this->color, $this->infinite);
	}

	public function getAmplifier() : int{
		return $this->amplifier;
	}

	/**
	 * Returns the level of this effect, which is always one higher than the amplifier.
	 */
	public function getEffectLevel() : int{
		return $this->amplifier + 1;
	}

	/**
	 * Returns whether this effect will produce some visible effect, such as bubbles or particles.
	 */
	public function isVisible() : bool{
		return $this->visible;
	}

	/**
	 * Returns whether the effect originated from the ambient environment.
	 * Ambient effects can originate from things such as a Beacon's area of effect radius.
	 * If this flag is set, the amount of visible particles will be reduced by a factor of 5.
	 */
	public function isAmbient() : bool{
		return $this->ambient;
	}

	/**
	 * Returns the particle colour of this effect instance. This can be overridden on a per-EffectInstance basis, so it
	 * is not reflective of the default colour of the effect.
	 */
	public function getColor() : Color{
		return $this->color;
	}

	/**
	 * Returns whether the effect is meant to last indefinitely
	 */
	public function isInfinite() : bool{
		return $this->infinite;
	}
}
