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
use quark\command\argument\ArgumentKind;
use function implode;
use function preg_match;
use function preg_split;
use const PHP_INT_MAX;
use const PREG_SPLIT_NO_EMPTY;

final class CommandSyntax{
	private string $path;
	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $literals;
	/**
	 * @var Argument[]
	 * @phpstan-var array<string, Argument<mixed>>
	 */
	private array $arguments = [];
	private int $minArguments = 0;
	private int $maxArguments = 0;
	private ?string $permission = null;
	private int $registrations = 0;

	/**
	 * @internal
	 * @throws \InvalidArgumentException
	 */
	public function __construct(string $path){
		$literals = preg_split('/\s+/', $path, -1, PREG_SPLIT_NO_EMPTY);
		if($literals === false){
			throw new \InvalidArgumentException("Invalid syntax path \"$path\"");
		}
		foreach($literals as $literal){
			if(preg_match('/\A[a-z0-9_-]+\z/', $literal) !== 1){
				throw new \InvalidArgumentException("Syntax literal \"$literal\" must be a lowercase word");
			}
		}
		$this->literals = $literals;
		$this->path = implode(" ", $literals);
	}

	/**
	 * @phpstan-param Argument<mixed> $argument
	 * @throws \InvalidArgumentException
	 * @throws \LogicException
	 */
	public function registerArgument(Argument $argument) : self{
		$this->checkNotRegistered();
		$name = $argument->getName();
		if(isset($this->arguments[$name])){
			throw new \InvalidArgumentException("Duplicate argument name \"$name\" in syntax \"$this->path\"");
		}
		if($this->maxArguments === PHP_INT_MAX){
			throw new \InvalidArgumentException("Argument \"$name\" cannot follow a greedy argument in syntax \"$this->path\"");
		}
		if($argument->isOptional()){
			$this->maxArguments++;
		}elseif($this->minArguments !== $this->maxArguments){
			throw new \InvalidArgumentException("Required argument \"$name\" cannot follow an optional argument in syntax \"$this->path\"");
		}else{
			$this->minArguments++;
			$this->maxArguments++;
		}
		if($argument->getKind() === ArgumentKind::TEXT){
			$this->maxArguments = PHP_INT_MAX;
		}
		$this->arguments[$name] = $argument;
		return $this;
	}

	/**
	 * @param Argument[] $arguments
	 * @phpstan-param list<Argument<mixed>> $arguments
	 * @throws \InvalidArgumentException
	 * @throws \LogicException
	 */
	public function registerArguments(array $arguments) : self{
		foreach($arguments as $argument){
			$this->registerArgument($argument);
		}
		return $this;
	}

	/**
	 * @throws \LogicException
	 */
	public function permission(?string $permission) : self{
		$this->checkNotRegistered();
		$this->permission = $permission;
		return $this;
	}

	public function getPath() : string{
		return $this->path;
	}

	/**
	 * @return Argument[]
	 * @phpstan-return array<string, Argument<mixed>>
	 */
	public function getArguments() : array{
		return $this->arguments;
	}

	public function getPermission() : ?string{
		return $this->permission;
	}

	public function testPermissionSilent(CommandSender $sender) : bool{
		return $this->permission === null || $sender->hasPermission($this->permission);
	}

	/**
	 * @internal
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getLiterals() : array{
		return $this->literals;
	}

	/** @internal */
	public function getMinArguments() : int{
		return $this->minArguments;
	}

	/** @internal */
	public function getMaxArguments() : int{
		return $this->maxArguments;
	}

	/** @internal */
	public function markRegistered(bool $registered) : void{
		//subcommand objects can be shared between commands
		$this->registrations += $registered ? 1 : -1;
	}

	private function checkNotRegistered() : void{
		if($this->registrations > 0){
			throw new \LogicException("Cannot change syntax \"$this->path\" while its command is registered");
		}
	}

	/** @internal */
	public function getUsage(string $commandLabel) : string{
		$usage = "/" . $commandLabel;
		if($this->path !== ""){
			$usage .= " " . $this->path;
		}
		foreach($this->arguments as $argument){
			$name = $argument->getName();
			if($argument->getKind() === ArgumentKind::TEXT){
				$name .= "...";
			}
			$usage .= $argument->isOptional() ? " [$name]" : " <$name>";
		}
		return $usage;
	}
}
