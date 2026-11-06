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

namespace quark\form\element;

use InvalidArgumentException;
use quark\form\FormValidationException;
use function gettype;
use function is_finite;
use function is_float;
use function is_int;

class Slider extends CustomFormElement{

	private float $min;
	private float $max;
	private float $step;
	private float $default;

	public function __construct(string $name, string $text, float $min, float $max, float $step = 1.0, ?float $default = null){
		parent::__construct($name, $text);

		if(!is_finite($min) || !is_finite($max) || $min > $max){
			throw new InvalidArgumentException("Slider bounds must be finite and min must not exceed max");
		}
		$this->min = $min;
		$this->max = $max;

		$default ??= $min;
		if(!is_finite($default) || $default < $min || $default > $max){
			throw new InvalidArgumentException("Default must be finite and in range $min ... $max");
		}
		$this->default = $default;

		if(!is_finite($step) || $step <= 0){
			throw new InvalidArgumentException("Step must be finite and greater than zero");
		}
		$this->step = $step;
	}

	public function getType() : string{
		return "slider";
	}

	public function validateValue($value) : void{
		if(!is_float($value) && !is_int($value)){
			throw new FormValidationException("Expected float, got " . gettype($value));
		}
		if(!is_finite($value) || $value < $this->min || $value > $this->max){
			throw new FormValidationException("Value $value is out of bounds (min $this->min, max $this->max)");
		}
	}

	public function getMin() : float{
		return $this->min;
	}

	public function getMax() : float{
		return $this->max;
	}

	public function getStep() : float{
		return $this->step;
	}

	public function getDefault() : float{
		return $this->default;
	}

	protected function serializeElementData() : array{
		return [
			"min" => $this->min,
			"max" => $this->max,
			"default" => $this->default,
			"step" => $this->step
		];
	}
}
