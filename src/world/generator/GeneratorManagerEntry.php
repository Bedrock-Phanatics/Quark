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

namespace quark\world\generator;

final class GeneratorManagerEntry{

	/**
	 * @phpstan-param class-string<Generator> $generatorClass
	 * @phpstan-param \Closure(string) : ?InvalidGeneratorOptionsException $presetValidator
	 */
	public function __construct(
		private string $generatorClass,
		private \Closure $presetValidator,
		private readonly bool $fast
	){}

	/** @phpstan-return class-string<Generator> */
	public function getGeneratorClass() : string{ return $this->generatorClass; }

	public function isFast() : bool{ return $this->fast; }

	/**
	 * @throws InvalidGeneratorOptionsException
	 */
	public function validateGeneratorOptions(string $generatorOptions) : void{
		if(($exception = ($this->presetValidator)($generatorOptions)) !== null){
			throw $exception;
		}
	}
}
