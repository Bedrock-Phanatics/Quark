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

namespace quark\form\type;

use InvalidArgumentException;
use function array_key_exists;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * @phpstan-type ResponseData array<string, mixed>
 */
class CustomFormResponse{
	/**
	 * @var mixed[]
	 * @phpstan-var ResponseData
	 */
	private array $data;

	/**
	 * @param mixed[] $data
	 *
	 * @phpstan-param ResponseData $data
	 */
	public function __construct(array $data){
		$this->data = $data;
	}

	public function getInt(string $name) : int{
		$value = $this->getValue($name);
		if(!is_int($value)){
			throw new InvalidArgumentException("Value \"$name\" is not an int");
		}
		return $value;
	}

	public function getString(string $name) : string{
		$value = $this->getValue($name);
		if(!is_string($value)){
			throw new InvalidArgumentException("Value \"$name\" is not a string");
		}
		return $value;
	}

	public function getFloat(string $name) : float{
		$value = $this->getValue($name);
		if(!is_float($value) && !is_int($value)){
			throw new InvalidArgumentException("Value \"$name\" is not a float or int");
		}
		return $value;
	}

	public function getBool(string $name) : bool{
		$value = $this->getValue($name);
		if(!is_bool($value)){
			throw new InvalidArgumentException("Value \"$name\" is not a bool");
		}
		return $value;
	}

	/**
	 * @return mixed[]
	 * @phpstan-return ResponseData
	 */
	public function getAll() : array{
		return $this->data;
	}

	public function getValue(string $name) : mixed{
		if(!array_key_exists($name, $this->data)){
			throw new InvalidArgumentException("Value \"$name\" not found");
		}
		return $this->data[$name];
	}
}
