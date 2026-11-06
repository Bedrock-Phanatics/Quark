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
	 * @param mixed[]              $data
	 *
	 * @phpstan-param ResponseData $data
	 */
	public function __construct(array $data){
		$this->data = $data;
	}

	public function getInt(string $name) : int{
		$this->checkExists($name);
		return $this->data[$name];
	}

	public function getString(string $name) : string{
		$this->checkExists($name);
		return $this->data[$name];
	}

	public function getFloat(string $name) : float{
		$this->checkExists($name);
		return $this->data[$name];
	}

	public function getBool(string $name) : bool{
		$this->checkExists($name);
		return $this->data[$name];
	}

	/**
	 * @return mixed[]
	 * @phpstan-return ResponseData
	 */
	public function getAll() : array{
		return $this->data;
	}

	private function checkExists(string $name) : void{
		if(!isset($this->data[$name])){
			throw new InvalidArgumentException("Value \"$name\" not found");
		}
	}
}
