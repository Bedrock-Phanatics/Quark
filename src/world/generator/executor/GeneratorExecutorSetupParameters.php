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

namespace quark\world\generator\executor;

use pmmp\thread\ThreadSafe;
use quark\world\generator\Generator;

final class GeneratorExecutorSetupParameters extends ThreadSafe{

	/**
	 * @phpstan-param class-string<covariant \quark\world\generator\Generator> $generatorClass
	 */
	public function __construct(
		public readonly int $worldMinY,
		public readonly int $worldMaxY,
		public readonly int $generatorSeed,
		public readonly string $generatorClass,
		public readonly string $generatorSettings,
	){}

	public function createGenerator() : Generator{
		/**
		 * @var Generator $generator
		 * @see Generator::__construct()
		 */
		$generator = new $this->generatorClass($this->generatorSeed, $this->generatorSettings);
		return $generator;
	}
}
