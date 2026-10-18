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

namespace quark;

use quark\utils\Config;
use function array_key_exists;
use function is_bool;
use function is_int;
use function is_string;
use function strtolower;

final class ServerConfigGroup{
	/**
	 * @var mixed[]
	 * @phpstan-var array<string, mixed>
	 */
	private array $propertyCache = [];

	/** @var array<string, string|bool> */
	private array $overrides = [];

	public function __construct(
		private Config $quarkYml,
		private Config $serverProperties,
		private bool $toml = false
	){
		$arguments = $_SERVER["argv"] ?? [];
		if(!is_array($arguments)){
			return;
		}
		foreach($arguments as $argument){
			if(is_string($argument) && str_starts_with($argument, "--")){
				[$key, $value] = array_pad(explode("=", substr($argument, 2), 2), 2, false);
				$this->overrides[(string) $key] = $value;
			}
		}
	}

	public function getProperty(string $variable, mixed $defaultValue = null) : mixed{
		if(!array_key_exists($variable, $this->propertyCache)){
			if(isset($this->overrides[$variable])){
				$this->propertyCache[$variable] = $this->overrides[$variable];
			}else{
				$this->propertyCache[$variable] = $this->quarkYml->getNested($variable);
			}
		}

		return $this->propertyCache[$variable] ?? $defaultValue;
	}

	public function getPropertyBool(string $variable, bool $defaultValue) : bool{
		return (bool) $this->getProperty($variable, $defaultValue);
	}

	public function getPropertyInt(string $variable, int $defaultValue) : int{
		return (int) $this->getProperty($variable, $defaultValue);
	}

	public function getPropertyString(string $variable, string $defaultValue) : string{
		return (string) $this->getProperty($variable, $defaultValue);
	}

	public function getConfigString(string $variable, string $defaultValue = "") : string{
		if(isset($this->overrides[$variable])){
			return (string) $this->overrides[$variable];
		}

		$value = $this->toml ? $this->serverProperties->getNested("server.$variable", $defaultValue) : ($this->serverProperties->exists($variable) ? $this->serverProperties->get($variable) : $defaultValue);
		return is_scalar($value) ? (string) $value : $defaultValue;
	}

	public function setConfigString(string $variable, string $value) : void{
		$this->toml ? $this->serverProperties->setNested("server.$variable", $value) : $this->serverProperties->set($variable, $value);
	}

	public function getConfigInt(string $variable, int $defaultValue = 0) : int{
		if(isset($this->overrides[$variable])){
			return (int) $this->overrides[$variable];
		}

		$value = $this->toml ? $this->serverProperties->getNested("server.$variable", $defaultValue) : ($this->serverProperties->exists($variable) ? $this->serverProperties->get($variable) : $defaultValue);
		return is_scalar($value) ? (int) $value : $defaultValue;
	}

	public function setConfigInt(string $variable, int $value) : void{
		$this->toml ? $this->serverProperties->setNested("server.$variable", $value) : $this->serverProperties->set($variable, $value);
	}

	public function getConfigBool(string $variable, bool $defaultValue = false) : bool{
		if(isset($this->overrides[$variable])){
			$value = $this->overrides[$variable];
		}else{
			$value = $this->toml ? $this->serverProperties->getNested("server.$variable", $defaultValue) : ($this->serverProperties->exists($variable) ? $this->serverProperties->get($variable) : $defaultValue);
		}
		if(is_bool($value)){
			return $value;
		}
		if(is_int($value)){
			return $value !== 0;
		}
		if(is_string($value)){
			switch(strtolower($value)){
				case "on":
				case "true":
				case "1":
				case "yes":
					return true;
			}
		}

		return false;
	}

	public function setConfigBool(string $variable, bool $value) : void{
		$this->toml ? $this->serverProperties->setNested("server.$variable", $value) : $this->serverProperties->set($variable, $value ? "1" : "0");
	}

	public function save() : void{
		if($this->toml){
			CoreConfig::validateServer($this->serverProperties->getAll());
		}
		if($this->serverProperties->hasChanged()){
			$this->serverProperties->save();
		}
		if($this->quarkYml !== $this->serverProperties && $this->quarkYml->hasChanged()){
			$this->quarkYml->save();
		}
	}
}
