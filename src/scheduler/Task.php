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

namespace quark\scheduler;

use quark\utils\Utils;

abstract class Task{
	/** @phpstan-var TaskHandler<static>|null  */
	private ?TaskHandler $taskHandler = null;

	/**
	 * @phpstan-return TaskHandler<static>|null
	 */
	final public function getHandler() : ?TaskHandler{
		return $this->taskHandler;
	}

	public function getName() : string{
		return Utils::getNiceClassName($this);
	}

	/**
	 * @phpstan-param TaskHandler<static>|null $taskHandler
	 */
	final public function setHandler(?TaskHandler $taskHandler) : void{
		if($this->taskHandler === null || $taskHandler === null){
			$this->taskHandler = $taskHandler;
		}
	}

	/**
	 * Actions to execute when run
	 *
	 * @throws CancelTaskException
	 */
	abstract public function onRun() : void;

	/**
	 * Actions to execute if the Task is cancelled
	 */
	public function onCancel() : void{

	}
}
