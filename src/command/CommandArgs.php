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

namespace quark\command;

use quark\player\Player;
use function get_debug_type;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

final class CommandArgs{

	/**
	 * @internal
	 * @phpstan-param array<string, mixed> $values
	 */
	public function __construct(
		private string $label,
		private string $subcommand,
		private array $values
	){}

	public function label() : string{
		return $this->label;
	}

	public function subcommand() : string{
		return $this->subcommand;
	}

	public function has(string $name) : bool{
		return isset($this->values[$name]);
	}

	/**
	 * @throws \LogicException
	 */
	public function get(string $name, mixed $default = null) : mixed{
		return $this->values[$name] ?? $default ?? throw new \LogicException("Argument \"$name\" has no value");
	}

	/**
	 * @throws \LogicException
	 */
	public function string(string $name, ?string $default = null) : string{
		$value = $this->get($name, $default);
		return is_string($value) ? $value : throw self::wrongType($name, "string", $value);
	}

	/**
	 * @throws \LogicException
	 */
	public function int(string $name, ?int $default = null) : int{
		$value = $this->get($name, $default);
		return is_int($value) ? $value : throw self::wrongType($name, "int", $value);
	}

	/**
	 * @throws \LogicException
	 */
	public function float(string $name, ?float $default = null) : float{
		$value = $this->get($name, $default);
		return is_float($value) ? $value : throw self::wrongType($name, "float", $value);
	}

	/**
	 * @throws \LogicException
	 */
	public function bool(string $name, ?bool $default = null) : bool{
		$value = $this->get($name, $default);
		return is_bool($value) ? $value : throw self::wrongType($name, "bool", $value);
	}

	/**
	 * @throws \LogicException
	 */
	public function player(string $name, ?Player $default = null) : Player{
		$value = $this->get($name, $default);
		return $value instanceof Player ? $value : throw self::wrongType($name, Player::class, $value);
	}

	private static function wrongType(string $name, string $expected, mixed $value) : \LogicException{
		return new \LogicException("Argument \"$name\" is " . get_debug_type($value) . ", not $expected");
	}
}
