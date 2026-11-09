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
use quark\command\argument\LiteralArgument;
use quark\command\internal\HandlerBinding;
use function count;
use function implode;
use function preg_split;
use const PHP_INT_MAX;
use const PREG_SPLIT_NO_EMPTY;

/**
 * One way to call a command: leading words, then typed arguments. A command can declare several syntaxes, even
 * with the same leading words, as long as no input could match two of them.
 */
final class CommandSyntax{
	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $literals = [];
	private string $path = "";
	/**
	 * Everything after the leading words, in order. Words in the middle are {@link LiteralArgument}s.
	 *
	 * @var Argument[]
	 * @phpstan-var list<Argument<mixed>>
	 */
	private array $arguments = [];
	/** @phpstan-var array<string, true> */
	private array $names = [];
	private bool $optionalSeen = false;
	private bool $greedySeen = false;
	private ?string $permission = null;
	/** @phpstan-var anyClosure|null */
	private ?\Closure $handler = null;
	private int $registrations = 0;
	private ?HandlerBinding $binding = null;

	private int $minTokens = 0;
	/**
	 * @var bool[]
	 * @phpstan-var array<int, true>
	 */
	private array $counts = [0 => true];
	private ?int $openFrom = null;
	private int $literalCount = 0;

	/**
	 * @internal
	 * @throws \InvalidArgumentException
	 */
	public function __construct(string $path = ""){
		$words = preg_split('/\s+/', $path, -1, PREG_SPLIT_NO_EMPTY);
		if($words === false){
			throw new \InvalidArgumentException("Invalid syntax path \"$path\"");
		}
		foreach($words as $word){
			$this->addArgument(new LiteralArgument($word));
		}
	}

	/**
	 * @phpstan-param Argument<mixed> $argument
	 * @throws \InvalidArgumentException
	 * @throws \LogicException
	 */
	public function registerArgument(Argument $argument) : self{
		$this->checkNotRegistered();
		$this->addArgument($argument);
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
	 * Requires a fixed word at this position. Case-insensitive, and not passed to the handler.
	 *
	 * @throws \InvalidArgumentException
	 * @throws \LogicException
	 */
	public function literal(string $word) : self{
		return $this->registerArgument(new LiteralArgument($word));
	}

	/**
	 * @phpstan-param Argument<mixed> $argument
	 */
	private function addArgument(Argument $argument) : void{
		$name = $argument->getName();
		$literal = $argument instanceof LiteralArgument;
		if($this->greedySeen){
			throw new \InvalidArgumentException("Argument \"$name\" cannot follow a greedy argument in syntax \"$this->path\"");
		}
		if(!$literal && isset($this->names[$name])){
			throw new \InvalidArgumentException("Duplicate argument name \"$name\" in syntax \"$this->path\"");
		}
		if($argument->getWidth() < 1){
			throw new \InvalidArgumentException("Argument \"$name\" must span at least one token");
		}

		if($literal && count($this->arguments) === 0){
			$this->literals[] = $argument->getLiteral();
			$this->path = implode(" ", $this->literals);
			$this->literalCount++;
			return;
		}

		$greedy = $argument->getKind() === ArgumentKind::TEXT;
		if($argument->isOptional()){
			$this->optionalSeen = true;
		}elseif($this->optionalSeen){
			throw new \InvalidArgumentException("Required argument \"$name\" cannot follow an optional argument in syntax \"$this->path\"");
		}

		$width = $argument->getWidth();
		if($greedy){
			$this->greedySeen = true;
			if($argument->isOptional()){
				$this->openFrom = $this->maxFinite() + 1;
			}else{
				$this->minTokens += 1;
				$this->counts = [];
				$this->openFrom = $this->minTokens;
			}
		}elseif($argument->isOptional()){
			$this->counts[$this->maxFinite() + $width] = true;
		}else{
			$this->minTokens += $width;
			$this->counts = [$this->minTokens => true];
		}

		if($literal){
			$this->literalCount++;
		}else{
			$this->names[$name] = true;
		}
		$this->arguments[] = $argument;
	}

	private function maxFinite() : int{
		$max = $this->minTokens;
		foreach($this->counts as $count => $_){
			$max = $count > $max ? $count : $max;
		}
		return $max;
	}

	/**
	 * @throws \LogicException
	 */
	public function permission(?string $permission) : self{
		$this->checkNotRegistered();
		$this->permission = $permission;
		return $this;
	}

	/**
	 * Handles this syntax. Either accept `(CommandSender $sender, CommandArgs $args)`, or the sender followed by one
	 * typed parameter per argument in order, skipping literals. Optional arguments need a default value. A sender
	 * parameter typed as a subclass such as Player restricts the syntax to that kind of sender. The signature is
	 * checked when the command is registered.
	 *
	 * @phpstan-param anyClosure $handler
	 * @throws \LogicException
	 */
	public function handler(\Closure $handler) : self{
		$this->checkNotRegistered();
		$this->handler = $handler;
		return $this;
	}

	public function getPath() : string{
		return $this->path;
	}

	/**
	 * @return Argument[]
	 * @phpstan-return list<Argument<mixed>>
	 */
	public function getArguments() : array{
		return $this->arguments;
	}

	public function getPermission() : ?string{
		return $this->permission;
	}

	/**
	 * @phpstan-return anyClosure|null
	 */
	public function getHandler() : ?\Closure{
		return $this->handler;
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
	public function getLiteralCount() : int{
		return $this->literalCount;
	}

	/**
	 * Whether the arguments after the leading words can be given exactly $count tokens.
	 *
	 * @internal
	 */
	public function acceptsTokenCount(int $count) : bool{
		return isset($this->counts[$count]) || ($this->openFrom !== null && $count >= $this->openFrom);
	}

	/** @internal */
	public function getMinTokens() : int{
		return $this->minTokens;
	}

	/**
	 * Largest number of tokens the arguments can take, PHP_INT_MAX for greedy ones.
	 *
	 * @internal
	 */
	public function getMaxTokens() : int{
		return $this->openFrom !== null ? PHP_INT_MAX : $this->maxFinite();
	}

	/** @internal */
	public function getBinding() : ?HandlerBinding{
		return $this->binding;
	}

	/** @internal */
	public function setBinding(?HandlerBinding $binding) : void{
		$this->binding = $binding;
	}

	/** @internal */
	public function markRegistered(bool $registered) : void{
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
			if($argument instanceof LiteralArgument){
				$usage .= " " . $argument->getLiteral();
				continue;
			}
			$name = $argument->getUsageLabel();
			if($argument->getKind() === ArgumentKind::TEXT){
				$name .= "...";
			}
			$usage .= $argument->isOptional() ? " [$name]" : " <$name>";
		}
		return $usage;
	}
}
