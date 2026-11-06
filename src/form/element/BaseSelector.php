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
use quark\utils\Utils;
use function array_values;
use function gettype;
use function is_int;

abstract class BaseSelector extends CustomFormElement{
	protected int $defaultOptionIndex;
	/** @var list<string> */
	protected array $options;

	/**
	 * @param string[] $options
	 *
	 * @throws InvalidArgumentException
	 */
	public function __construct(string $name, string $text, array $options, int $defaultOptionIndex = 0){
		parent::__construct($name, $text);
		Utils::validateArrayValueType($options, static function(string $option) : void{});
		$this->options = array_values($options);

		if(!isset($this->options[$defaultOptionIndex])){
			throw new InvalidArgumentException("No option at index $defaultOptionIndex, cannot set as default");
		}
		$this->defaultOptionIndex = $defaultOptionIndex;
	}

	public function validateValue($value) : void{
		if(!is_int($value)){
			throw new FormValidationException("Expected int, got " . gettype($value));
		}
		if(!isset($this->options[$value])){
			throw new FormValidationException("Option $value does not exist");
		}
	}

	/**
	 * Returns the text of the option at the specified index, or null if it doesn't exist.
	 */
	public function getOption(int $index) : ?string{
		return $this->options[$index] ?? null;
	}

	public function getDefaultOptionIndex() : int{
		return $this->defaultOptionIndex;
	}

	public function getDefaultOption() : string{
		return $this->options[$this->defaultOptionIndex];
	}

	/**
	 * @return list<string>
	 */
	public function getOptions() : array{
		return $this->options;
	}
}
