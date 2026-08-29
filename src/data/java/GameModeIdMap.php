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

namespace quark\data\java;

use quark\player\GameMode;
use quark\utils\SingletonTrait;
use function array_key_exists;
use function spl_object_id;

final class GameModeIdMap{
	use SingletonTrait;

	/**
	 * @var GameMode[]
	 * @phpstan-var array<int, GameMode>
	 */
	private array $idToEnum = [];

	/**
	 * @var int[]
	 * @phpstan-var array<int, int>
	 */
	private array $enumToId = [];

	public function __construct(){
		foreach(GameMode::cases() as $case){
			$this->register(match($case){
				GameMode::SURVIVAL => 0,
				GameMode::CREATIVE => 1,
				GameMode::ADVENTURE => 2,
				GameMode::SPECTATOR => 3,
			}, $case);
		}
	}

	private function register(int $id, GameMode $type) : void{
		$this->idToEnum[$id] = $type;
		$this->enumToId[spl_object_id($type)] = $id;
	}

	public function fromId(int $id) : ?GameMode{
		return $this->idToEnum[$id] ?? null;
	}

	public function toId(GameMode $type) : int{
		$k = spl_object_id($type);
		if(!array_key_exists($k, $this->enumToId)){
			throw new \InvalidArgumentException("Game mode $type->name does not have a mapped ID"); //this should never happen
		}
		return $this->enumToId[$k];
	}
}
