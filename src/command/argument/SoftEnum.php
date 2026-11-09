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

use function array_values;
use function count;
use function preg_match;
use function spl_object_id;
use function strlen;
use function strtolower;

/**
 * A dynamic set of words. Changes are pushed to clients with soft enum update packets instead of resending the
 * command list.
 */
final class SoftEnum{
	public const int TYPE_ADD = 0;
	public const int TYPE_REMOVE = 1;
	public const int TYPE_SET = 2;

	public const int MAX_VALUES = 4096;
	public const int MAX_VALUE_LENGTH = 128;

	/**
	 * @var string[]
	 * @phpstan-var array<string, string>
	 */
	private array $values = [];
	/** @phpstan-var array<string, string> */
	private array $added = [];
	/** @phpstan-var array<string, string> */
	private array $removed = [];
	private bool $replaced = false;
	private int $version = 0;
	private int $consumedVersion = 0;
	/** @phpstan-var array<int, \Closure() : void> */
	private array $watchers = [];

	/**
	 * @param string[] $values
	 * @phpstan-param list<string> $values
	 * @throws \InvalidArgumentException
	 */
	public function __construct(private string $name, array $values = []){
		if(preg_match('/\A[A-Za-z0-9_.-]+\z/', $name) !== 1){
			throw new \InvalidArgumentException("Invalid enum name \"$name\"");
		}
		foreach($values as $value){
			$this->insert($value);
		}
	}

	public function getName() : string{
		return $this->name;
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getValues() : array{
		return array_values($this->values);
	}

	public function has(string $value) : bool{
		return isset($this->values[strtolower($value)]);
	}

	/**
	 * Returns the value as it was added, matching case-insensitively.
	 */
	public function find(string $value) : ?string{
		return $this->values[strtolower($value)] ?? null;
	}

	/**
	 * @throws \InvalidArgumentException
	 */
	public function add(string ...$values) : void{
		$changed = false;
		foreach($values as $value){
			$changed = $this->insert($value) || $changed;
		}
		if($changed){
			$this->notify();
		}
	}

	public function remove(string ...$values) : void{
		$changed = false;
		foreach($values as $value){
			$key = strtolower($value);
			if(!isset($this->values[$key])){
				continue;
			}
			$changed = true;
			$original = $this->values[$key];
			unset($this->values[$key]);
			if(isset($this->added[$key])){
				unset($this->added[$key]);
			}else{
				$this->removed[$key] = $original;
			}
		}
		if($changed){
			$this->notify();
		}
	}

	/**
	 * @param string[] $values
	 * @phpstan-param list<string> $values
	 * @throws \InvalidArgumentException
	 */
	public function set(array $values) : void{
		$old = $this->values;
		$this->values = [];
		try{
			foreach($values as $value){
				$this->insert($value, false);
			}
		}catch(\InvalidArgumentException $e){
			$this->values = $old;
			throw $e;
		}
		$this->replaced = true;
		$this->added = [];
		$this->removed = [];
		$this->notify();
	}

	private function insert(string $value, bool $track = true) : bool{
		if(strlen($value) === 0 || strlen($value) > self::MAX_VALUE_LENGTH || preg_match('/[\s"]/', $value) === 1){
			throw new \InvalidArgumentException("Invalid value for enum \"$this->name\"");
		}
		$key = strtolower($value);
		if(isset($this->values[$key])){
			return false;
		}
		if(count($this->values) >= self::MAX_VALUES){
			throw new \InvalidArgumentException("Enum \"$this->name\" cannot hold more than " . self::MAX_VALUES . " values");
		}
		$this->values[$key] = $value;
		if($track){
			if(isset($this->removed[$key])){
				unset($this->removed[$key]);
			}else{
				$this->added[$key] = $value;
			}
		}
		return true;
	}

	/**
	 * @internal
	 * @param \Closure() : void $watcher
	 */
	public function watch(object $owner, \Closure $watcher) : void{
		$this->watchers[spl_object_id($owner)] = $watcher;
	}

	/** @internal */
	public function unwatch(object $owner) : void{
		unset($this->watchers[spl_object_id($owner)]);
	}

	/**
	 * Takes the changes not yet sent to clients as update packet payloads. An empty result means nothing changed.
	 *
	 * @internal
	 * @return string[][]
	 * @phpstan-return list<array{int, list<string>}>
	 */
	public function consumeChanges() : array{
		$changes = [];
		if($this->replaced){
			$changes[] = [self::TYPE_SET, $this->getValues()];
		}else{
			if(count($this->removed) !== 0){
				$changes[] = [self::TYPE_REMOVE, array_values($this->removed)];
			}
			if(count($this->added) !== 0){
				$changes[] = [self::TYPE_ADD, array_values($this->added)];
			}
		}
		$this->replaced = false;
		$this->added = [];
		$this->removed = [];
		$this->consumedVersion = $this->version;
		return $changes;
	}

	/** @internal */
	public function getVersion() : int{
		return $this->version;
	}

	/** @internal */
	public function getConsumedVersion() : int{
		return $this->consumedVersion;
	}

	private function notify() : void{
		++$this->version;
		foreach($this->watchers as $watcher){
			$watcher();
		}
	}
}
