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

use quark\scheduler\AsyncTask;

class AsyncGeneratorRegisterTask extends AsyncTask{

	public function __construct(
		private readonly GeneratorExecutorSetupParameters $setupParameters,
		private readonly int $contextId
	){}

	public function onRun() : void{
		$setupParameters = $this->setupParameters;
		$generator = $setupParameters->createGenerator();
		ThreadLocalGeneratorContext::register(new ThreadLocalGeneratorContext($generator, $setupParameters->worldMinY, $setupParameters->worldMaxY), $this->contextId);
	}
}
