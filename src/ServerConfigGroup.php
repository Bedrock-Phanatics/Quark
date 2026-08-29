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
use function getopt;
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

	public function __construct(
		private Config $quarkYml,
		private Config $serverProperties
	){}

	public function getProperty(string $variable, mixed $defaultValue = null) : mixed{
		if(!array_key_exists($variable, $this->propertyCache)){
			$v = getopt("", ["$variable::"]);
			if(isset($v[$variable])){
				$this->propertyCache[$variable] = $v[$variable];
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
		$v = getopt("", ["$variable::"]);
		if(isset($v[$variable])){
			return (string) $v[$variable];
		}

		return $this->serverProperties->exists($variable) ? (string) $this->serverProperties->get($variable) : $defaultValue;
	}

	public function setConfigString(string $variable, string $value) : void{
		$this->serverProperties->set($variable, $value);
	}

	public function getConfigInt(string $variable, int $defaultValue = 0) : int{
		$v = getopt("", ["$variable::"]);
		if(isset($v[$variable])){
			return (int) $v[$variable];
		}

		return $this->serverProperties->exists($variable) ? (int) $this->serverProperties->get($variable) : $defaultValue;
	}

	public function setConfigInt(string $variable, int $value) : void{
		$this->serverProperties->set($variable, $value);
	}

	public function getConfigBool(string $variable, bool $defaultValue = false) : bool{
		$v = getopt("", ["$variable::"]);
		if(isset($v[$variable])){
			$value = $v[$variable];
		}else{
			$value = $this->serverProperties->exists($variable) ? $this->serverProperties->get($variable) : $defaultValue;
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
		$this->serverProperties->set($variable, $value ? "1" : "0");
	}

	public function save() : void{
		if($this->serverProperties->hasChanged()){
			$this->serverProperties->save();
		}
		if($this->quarkYml->hasChanged()){
			$this->quarkYml->save();
		}
	}
}
