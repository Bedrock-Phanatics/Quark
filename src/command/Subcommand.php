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

use quark\command\argument\Argument;

abstract class Subcommand{
	private CommandSyntax $syntax;

	/**
	 * @throws \InvalidArgumentException
	 */
	public function __construct(string $path){
		$this->syntax = new CommandSyntax($path);
	}

	public function setPermission(?string $permission) : void{
		$this->syntax->permission($permission);
	}

	/**
	 * @phpstan-param Argument<mixed> $argument
	 * @throws \InvalidArgumentException
	 */
	public function registerArgument(Argument $argument) : void{
		$this->syntax->registerArgument($argument);
	}

	/**
	 * @param Argument[] $arguments
	 * @phpstan-param list<Argument<mixed>> $arguments
	 * @throws \InvalidArgumentException
	 */
	public function registerArguments(array $arguments) : void{
		$this->syntax->registerArguments($arguments);
	}

	abstract protected function run(CommandSender $sender, CommandArgs $args) : void;

	/** @internal */
	final public function getSyntax() : CommandSyntax{
		return $this->syntax;
	}

	/** @internal */
	final public function dispatch(CommandSender $sender, CommandArgs $args) : void{
		$this->run($sender, $args);
	}
}
