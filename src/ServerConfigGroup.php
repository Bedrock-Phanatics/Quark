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
use quark\utils\Utils;
use function array_key_exists;
use function array_pad;
use function array_replace_recursive;
use function count;
use function explode;
use function file_exists;
use function filter_var;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function is_scalar;
use function is_string;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;
use const FILTER_NULL_ON_FAILURE;
use const FILTER_VALIDATE_BOOLEAN;
use const FILTER_VALIDATE_INT;

final class ServerConfigGroup{
	/**
	 * @var mixed[]
	 * @phpstan-var array<string, mixed>
	 */
	private array $propertyCache = [];

	/** @var array<string, string|int|float|bool> */
	private array $overrides = [];
	/** @var array<string, mixed> */
	private array $configOverrides = [];

	public static function load(string $path) : self{
		if(!file_exists($path)){
			ConfigurationMigration::createServer($path);
		}
		$config = new Config($path, Config::TOML);
		return new self($config, $config, true);
	}

	public function __construct(
		private Config $quarkYml,
		private Config $serverProperties,
		private bool $toml = false
	){
		$arguments = $_SERVER["argv"] ?? [];
		if(is_array($arguments)){
			foreach($arguments as $argument){
				if(is_string($argument) && str_starts_with($argument, "--")){
					[$key, $value] = array_pad(explode("=", substr($argument, 2), 2), 2, false);
					$key = (string) $key;
					if($this->toml && str_starts_with($key, "server.") && !str_contains(substr($key, 7), ".")){
						$key = substr($key, 7);
					}
					$this->overrides[$key] = $value;
				}
			}
		}
		if($this->toml){
			$this->applyOverrides();
			CoreConfig::validateServer($this->serverProperties->getAll(), $this->configOverrides);
		}
	}

	private function applyOverrides() : void{
		$defaults = CoreConfig::defaults();
		$serverDefaults = $defaults["server"] ?? [];
		$changes = [];
		foreach(Utils::stringifyKeys($this->overrides) as $name => $raw){
			$path = is_array($serverDefaults) && isset($serverDefaults[$name]) ? ["server", $name] : explode(".", $name, 4);
			$expected = $defaults;
			foreach($path as $segment){
				$expected = is_array($expected) ? ($expected[$segment] ?? null) : null;
			}
			if($expected === null || $name === "config-version" || $name === BootstrapOptions::PLUGINS){
				continue;
			}
			if(!is_string($raw) || is_array($expected)){
				throw new \InvalidArgumentException("Invalid --$name: pass a scalar value with --$name=value or edit config/server.toml");
			}
			$value = match(true){
				is_bool($expected) => trim($raw) !== "" ? filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null,
				is_int($expected), $name === "settings.async-workers" && $raw !== "auto" => filter_var($raw, FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE),
				is_float($expected) => is_numeric($raw) ? (float) $raw : null,
				default => $raw,
			};
			if($value === null){
				throw new \InvalidArgumentException("Invalid value for --$name");
			}
			$this->overrides[$name] = $value;
			$patch = $value;
			for($index = count($path) - 1; $index > 0; --$index){
				$patch = [$path[$index] => $patch];
			}
			$changes = array_replace_recursive($changes, [$path[0] => $patch]);
		}
		$this->configOverrides = $changes;
	}

	/** @return array<string, mixed> */
	public function getLoggingSettings() : array{
		/** @var array<string, mixed> $logging */
		$logging = $this->getProperty("logging", []);
		foreach(Utils::stringifyKeys($this->overrides) as $name => $value){
			if(str_starts_with($name, "logging.")){
				$logging[substr($name, 8)] = $value;
			}
		}
		return $logging;
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
		if($this->serverProperties->hasChanged()){
			if($this->toml){
				CoreConfig::validateServer($this->serverProperties->getAll(), $this->configOverrides);
			}
			$this->serverProperties->save();
		}
		if($this->quarkYml !== $this->serverProperties && $this->quarkYml->hasChanged()){
			$this->quarkYml->save();
		}
	}
}
