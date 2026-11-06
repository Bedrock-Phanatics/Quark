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

use quark\form\FormValidationException;
use function gettype;
use function is_bool;

/**
 * Represents a UI on/off switch. The switch may have a default value.
 */
class Toggle extends CustomFormElement{
	private bool $default;

	public function __construct(string $name, string $text, bool $defaultValue = false){
		parent::__construct($name, $text);
		$this->default = $defaultValue;
	}

	public function getType() : string{
		return "toggle";
	}

	public function getDefaultValue() : bool{
		return $this->default;
	}

	public function validateValue($value) : void{
		if(!is_bool($value)){
			throw new FormValidationException("Expected bool, got " . gettype($value));
		}
	}

	protected function serializeElementData() : array{
		return [
			"default" => $this->default
		];
	}
}
