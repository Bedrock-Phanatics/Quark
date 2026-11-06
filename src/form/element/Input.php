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
use function is_string;

/**
 * Element which accepts text input. The text-box can have a default value, and may also have a text hint when there is
 * no text in the box.
 */
class Input extends CustomFormElement{

	/** @var string */
	private string $hint;
	/** @var string */
	private string $default;

	public function __construct(string $name, string $text, string $hintText = "", string $defaultText = ""){
		parent::__construct($name, $text);
		$this->hint = $hintText;
		$this->default = $defaultText;
	}

	public function getType() : string{
		return "input";
	}

	public function validateValue($value) : void{
		if(!is_string($value)){
			throw new FormValidationException("Expected string, got " . gettype($value));
		}
	}

	/**
	 * Returns the text shown in the text-box when the box is not focused and there is no text in it.
	 */
	public function getHintText() : string{
		return $this->hint;
	}

	/**
	 * Returns the text which will be in the text-box by default.
	 */
	public function getDefaultText() : string{
		return $this->default;
	}

	protected function serializeElementData() : array{
		return [
			"placeholder" => $this->hint,
			"default" => $this->default
		];
	}
}
