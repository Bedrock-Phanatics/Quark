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

namespace quark\command\internal;

use quark\command\argument\ArgumentKind;
use quark\command\argument\ArgumentParseException;
use quark\command\Command;
use quark\command\CommandArgs;
use quark\command\CommandSender;
use quark\command\CommandSyntax;
use quark\lang\KnownTranslationFactory;
use quark\lang\Translatable;
use quark\permission\PermissionManager;
use function array_slice;
use function count;
use function implode;
use function ksort;
use function str_contains;
use function str_starts_with;
use const SORT_STRING;

/**
 * @internal
 *
 * @phpstan-type PreparedSyntaxes array{
 *     routes: array<string, CommandSyntax>,
 *     prefixes: array<string, true>
 * }
 */
final class SyntaxMatcher{

	private function __construct(){
		//NOOP
	}

	/**
	 * @phpstan-return PreparedSyntaxes|null
	 *
	 * @throws \InvalidArgumentException
	 * @throws \LogicException
	 */
	public static function prepare(Command $command) : ?array{
		$name = $command->getName();
		$overridesExecute = self::overrides($command, "execute");
		$overridesRun = self::overrides($command, "run");
		$routes = $command->getSyntaxes();

		if($overridesExecute){
			if($overridesRun || count($routes) !== 0){
				throw new \LogicException("Command \"$name\" cannot override execute() and declare syntaxes or override run()");
			}
			return null;
		}
		if($overridesRun){
			$routes[""] ??= new CommandSyntax("");
		}else{
			if(count($routes) === 0){
				throw new \LogicException("Command \"$name\" must override run() or execute()");
			}
			$subcommands = $command->getSubcommands();
			foreach($routes as $syntax){
				$path = $syntax->getPath();
				if(!isset($subcommands[$path])){
					throw new \LogicException("Syntax \"$path\" of command \"$name\" needs run() or a subcommand class");
				}
			}
		}

		$permissionManager = PermissionManager::getInstance();
		$prefixes = [];
		foreach($routes as $syntax){
			$permission = $syntax->getPermission();
			if($permission !== null && $permissionManager->getPermission($permission) === null){
				throw new \InvalidArgumentException("Syntax \"" . $syntax->getPath() . "\" uses non-existing permission \"$permission\"");
			}
			$prefix = "";
			foreach($syntax->getLiterals() as $index => $literal){
				$prefix = $index === 0 ? $literal : $prefix . " " . $literal;
				$prefixes[$prefix] = true;
			}
			foreach($syntax->getArguments() as $argument){
				if($argument->getKind() === ArgumentKind::ENUM && count($argument->getEnumValues()) === 0){
					throw new \InvalidArgumentException("Enum argument \"" . $argument->getName() . "\" of command \"$name\" has no values");
				}
			}
		}
		ksort($routes, SORT_STRING);

		return ["routes" => $routes, "prefixes" => $prefixes];
	}

	private static function overrides(Command $command, string $method) : bool{
		return new \ReflectionMethod($command, $method)->getDeclaringClass()->getName() !== Command::class;
	}

	/**
	 * @phpstan-param PreparedSyntaxes $prepared
	 * @param string[] $args
	 * @phpstan-param list<string> $args
	 *
	 * @throws CommandInputException
	 */
	public static function parse(Command $command, array $prepared, CommandSender $sender, string $commandLabel, array $args) : ?CommandArgs{
		$count = count($args);
		[$path, $consumed] = self::matchLiterals($prepared, $args);

		$remaining = $count - $consumed;
		$syntax = $prepared["routes"][$path] ?? null;
		if($syntax === null || $remaining < $syntax->getMinArguments() || $remaining > $syntax->getMaxArguments()){
			throw new CommandInputException(null, self::findUsages($command, $prepared, $sender, $commandLabel, $path));
		}

		$permission = $syntax->getPermission();
		if($permission !== null && !$command->testPermission($sender, $permission)){
			return null;
		}

		$values = [];
		$position = $consumed;
		foreach($syntax->getArguments() as $argument){
			if($position >= $count){
				break;
			}
			$name = $argument->getName();
			$input = $argument->getKind() === ArgumentKind::TEXT ? implode(" ", array_slice($args, $position)) : $args[$position];
			try{
				$values[$name] = $argument->parse($input, $sender);
			}catch(ArgumentParseException $e){
				throw new CommandInputException(
					KnownTranslationFactory::quark_command_error_invalidArgument($name, $e->getUserMessage()),
					[$syntax->getUsage($commandLabel)]
				);
			}
			$position++;
		}
		return new CommandArgs($commandLabel, $path, $values);
	}

	/**
	 * @phpstan-param PreparedSyntaxes $prepared
	 * @param string[] $args
	 * @phpstan-param list<string> $args
	 */
	public static function canReachPermittedSyntax(array $prepared, CommandSender $sender, array $args, bool $moreArgs) : bool{
		[$path, $consumed] = self::matchLiterals($prepared, $args);
		$remaining = count($args) - $consumed;
		//unknown trailing arguments can still name deeper literals
		$deeper = $moreArgs && $remaining === 0;
		foreach($prepared["routes"] as $syntax){
			$syntaxPath = $syntax->getPath();
			if($syntaxPath === $path){
				$reachable = $syntax->getMaxArguments() >= $remaining && ($moreArgs || $syntax->getMinArguments() <= $remaining);
			}else{
				$reachable = $deeper && self::isBelow($path, $syntaxPath);
			}
			if($reachable && $syntax->testPermissionSilent($sender)){
				return true;
			}
		}
		return false;
	}

	/**
	 * @phpstan-param PreparedSyntaxes $prepared
	 * @param string[] $args
	 * @phpstan-param list<string> $args
	 * @phpstan-return array{string, int}
	 */
	private static function matchLiterals(array $prepared, array $args) : array{
		$path = "";
		$consumed = 0;
		$candidate = "";
		//commit to the longest declared literal prefix, regardless of permissions or later arguments
		foreach($args as $i => $arg){
			//a quoted token may contain spaces and must not match several literals
			if(str_contains($arg, " ")){
				break;
			}
			$candidate = $i === 0 ? $arg : $candidate . " " . $arg;
			if(!isset($prepared["prefixes"][$candidate])){
				break;
			}
			$path = $candidate;
			$consumed = $i + 1;
		}
		return [$path, $consumed];
	}

	private static function isBelow(string $path, string $syntaxPath) : bool{
		return $path === "" || str_starts_with($syntaxPath, $path . " ");
	}

	/**
	 * @phpstan-param PreparedSyntaxes $prepared
	 * @phpstan-return list<Translatable|string>
	 */
	private static function findUsages(Command $command, array $prepared, CommandSender $sender, string $commandLabel, string $path) : array{
		$usages = [];
		foreach($prepared["routes"] as $syntax){
			$syntaxPath = $syntax->getPath();
			if(($syntaxPath === $path || self::isBelow($path, $syntaxPath)) && $syntax->testPermissionSilent($sender)){
				$usages[] = $syntax->getUsage($commandLabel);
			}
		}
		return count($usages) !== 0 ? $usages : [$command->getUsage()];
	}
}
