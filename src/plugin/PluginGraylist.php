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

namespace quark\plugin;

use function array_fill_keys;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function is_array;
use function is_float;
use function is_int;
use function is_string;
use function trim;

class PluginGraylist{

	/**
	 * @var true[]
	 * @phpstan-var array<array-key, true>
	 */
	private array $plugins;
	private bool $isWhitelist = false;

	/**
	 * @param string[] $plugins
	 * @phpstan-param list<string> $plugins
	 */
	public function __construct(array $plugins = [], bool $whitelist = false){
		$this->plugins = array_fill_keys($plugins, true);
		$this->isWhitelist = $whitelist;
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getPlugins() : array{
		return array_map(static fn(string|int $name) : string => (string) $name, array_keys($this->plugins));
	}

	public function isWhitelist() : bool{
		return $this->isWhitelist;
	}

	/**
	 * Returns whether the given name is permitted by this graylist.
	 */
	public function isAllowed(string $name) : bool{
		return $this->isWhitelist() === isset($this->plugins[$name]);
	}

	/**
	 * @param mixed[] $array
	 */
	public static function fromArray(array $array) : PluginGraylist{
		$mode = $array["mode"] ?? null;
		if($mode !== "whitelist" && $mode !== "blacklist"){
			throw new \InvalidArgumentException("Invalid mode in plugins.toml: expected 'whitelist' or 'blacklist'");
		}
		$plugins = [];
		if(array_key_exists("plugins", $array)){
			if(!is_array($array["plugins"]) || !array_is_list($array["plugins"])){
				throw new \InvalidArgumentException("Invalid plugins in plugins.toml: expected a list of plugin names");
			}
			foreach($array["plugins"] as $index => $name){
				if((!is_string($name) && !is_int($name) && !is_float($name)) || trim((string) $name) === ""){
					throw new \InvalidArgumentException("Invalid plugins entry $index in plugins.toml: expected a plugin name");
				}
				$plugins[] = (string) $name;
			}
		}
		return new PluginGraylist($plugins, $mode === "whitelist");
	}

	/**
	 * @return mixed[]
	 * @phpstan-return array<string, mixed>
	 */
	public function toArray() : array{
		return [
			"mode" => $this->isWhitelist ? 'whitelist' : 'blacklist',
			"plugins" => $this->plugins
		];
	}
}
