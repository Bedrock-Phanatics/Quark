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

/**
 * Element which displays some text on a form.
 */
class Label extends CustomFormElement{

	public function getType() : string{
		return "label";
	}

	public function validateValue($value) : void{
		if($value !== null){
			throw new FormValidationException("Expected null, got " . gettype($value));
		}
	}

	protected function serializeElementData() : array{
		return [];
	}
}
