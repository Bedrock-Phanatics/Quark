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

namespace quark\world;

use pocketmine\math\Vector3;
use quark\utils\Limits;
use quark\utils\Utils;
use quark\world\generator\Generator;
use quark\world\generator\normal\Normal;
use function random_int;

/**
 * Represents user-customizable settings for world creation
 */
final class WorldCreationOptions{

	/** @phpstan-var class-string<Generator> */
	private string $generatorClass = Normal::class;
	private int $seed;
	private int $difficulty = World::DIFFICULTY_NORMAL;
	private string $generatorOptions = "";
	private Vector3 $spawnPosition;

	public function __construct(){
		$this->seed = random_int(Limits::INT32_MIN, Limits::INT32_MAX);
		$this->spawnPosition = new Vector3(256, 70, 256);
	}

	public static function create() : self{
		return new self();
	}

	/** @phpstan-return class-string<Generator> */
	public function getGeneratorClass() : string{ return $this->generatorClass; }

	/**
	 * @phpstan-param class-string<Generator> $generatorClass
	 * @return $this
	 */
	public function setGeneratorClass(string $generatorClass) : self{
		Utils::testValidInstance($generatorClass, Generator::class);
		$this->generatorClass = $generatorClass;
		return $this;
	}

	public function getSeed() : int{ return $this->seed; }

	/** @return $this */
	public function setSeed(int $seed) : self{
		$this->seed = $seed;
		return $this;
	}

	public function getDifficulty() : int{ return $this->difficulty; }

	/** @return $this */
	public function setDifficulty(int $difficulty) : self{
		$this->difficulty = $difficulty;
		return $this;
	}

	public function getGeneratorOptions() : string{ return $this->generatorOptions; }

	/** @return $this */
	public function setGeneratorOptions(string $generatorOptions) : self{
		$this->generatorOptions = $generatorOptions;
		return $this;
	}

	public function getSpawnPosition() : Vector3{ return $this->spawnPosition; }

	/** @return $this */
	public function setSpawnPosition(Vector3 $spawnPosition) : self{
		$this->spawnPosition = $spawnPosition;
		return $this;
	}
}
