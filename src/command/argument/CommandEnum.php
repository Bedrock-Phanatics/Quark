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

namespace quark\command\argument;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_unique;
use function count;
use function get_debug_type;
use function is_string;
use function preg_match;
use function strtolower;
use function strval;

/**
 * A fixed set of lowercase words, each mapped to a value. Share one instance between commands so the client sees
 * a single enum.
 */
final class CommandEnum{
	/**
	 * @var mixed[]
	 * @phpstan-var array<string, mixed>
	 */
	private array $map;
	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $values;
	private string $valueType;

	/**
	 * @param mixed[] $map
	 * @phpstan-param array<int|string, mixed> $map
	 */
	private function __construct(private string $name, array $map){
		if(preg_match('/\A[A-Za-z0-9_.-]+\z/', $name) !== 1){
			throw new \InvalidArgumentException("Invalid enum name \"$name\"");
		}
		if(count($map) === 0){
			throw new \InvalidArgumentException("Enum \"$name\" has no values");
		}
		$types = [];
		$clean = [];
		foreach($map as $key => $value){
			$key = (string) $key;
			if(preg_match('/\A[a-z0-9_.:-]+\z/', $key) !== 1){
				throw new \InvalidArgumentException("Value \"$key\" of enum \"$name\" must be a lowercase word");
			}
			if($value === null){
				throw new \InvalidArgumentException("Value \"$key\" of enum \"$name\" cannot map to null");
			}
			$types[get_debug_type($value)] = true;
			$clean[$key] = $value;
		}
		$this->map = $clean;
		//numeric words become integer array keys
		$this->values = array_map(strval(...), array_keys($clean));
		$this->valueType = count($types) === 1 ? array_keys($types)[0] : "mixed";
	}

	/**
	 * @param string[] $values
	 * @phpstan-param list<string> $values
	 * @throws \InvalidArgumentException
	 */
	public static function fromValues(string $name, array $values) : self{
		if(!array_is_list($values)){
			throw new \InvalidArgumentException("Values of enum \"$name\" must be a list");
		}
		if(count(array_unique($values)) !== count($values)){
			throw new \InvalidArgumentException("Values of enum \"$name\" must be unique");
		}
		$map = [];
		foreach($values as $value){
			if(!is_string($value)){
				throw new \InvalidArgumentException("Values of enum \"$name\" must be strings");
			}
			$map[$value] = $value;
		}
		return new self($name, $map);
	}

	/**
	 * @param mixed[] $map
	 * @phpstan-param array<int|string, mixed> $map
	 * @throws \InvalidArgumentException
	 */
	public static function fromMap(string $name, array $map) : self{
		return new self($name, $map);
	}

	/**
	 * Uses the backing value of string-backed cases and the case name otherwise, lowercased.
	 *
	 * @phpstan-param class-string<\UnitEnum> $class
	 * @throws \InvalidArgumentException
	 */
	public static function fromCases(string $class, ?string $name = null) : self{
		$map = [];
		foreach($class::cases() as $case){
			$key = strtolower($case instanceof \BackedEnum && is_string($case->value) ? $case->value : $case->name);
			if(array_key_exists($key, $map)){
				throw new \InvalidArgumentException("Cases of $class collide on \"$key\" when lowercased");
			}
			$map[$key] = $case;
		}
		return new self($name ?? (new \ReflectionClass($class))->getShortName(), $map);
	}

	public function getName() : string{
		return $this->name;
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getValues() : array{
		return $this->values;
	}

	public function find(string $token) : mixed{
		return $this->map[strtolower($token)] ?? null;
	}

	public function getValueType() : string{
		return $this->valueType;
	}
}
