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

use quark\command\argument\LiteralArgument;
use quark\command\Command;
use quark\command\CommandArgs;
use quark\command\CommandSender;
use quark\command\CommandSyntax;
use function array_slice;
use function count;
use function in_array;
use function is_a;

/**
 * How to call the handler of a syntax, worked out once when the command is registered.
 *
 * @internal
 */
final class HandlerBinding{
	/** Handler takes the sender and a {@link CommandArgs}. */
	public const int ARGS = 0;
	/** Handler takes the sender and one typed parameter per value argument. */
	public const int TYPED = 1;

	private function __construct(
		public readonly int $mode,
		public readonly ?string $senderClass
	){}

	/**
	 * @phpstan-param anyClosure $handler
	 * @throws \LogicException
	 */
	public static function inspect(Command $command, CommandSyntax $syntax, \Closure $handler) : self{
		$where = "Handler of \"" . $syntax->getUsage($command->getName()) . "\"";
		$parameters = (new \ReflectionFunction($handler))->getParameters();
		if(count($parameters) === 0){
			throw new \LogicException("$where must accept the command sender");
		}
		$senderClass = self::senderClass($parameters[0]->getType(), $where);

		$rest = array_slice($parameters, 1);
		if(count($rest) === 1 && self::isNamed($rest[0]->getType(), CommandArgs::class)){
			return new self(self::ARGS, $senderClass);
		}

		$values = [];
		foreach($syntax->getArguments() as $argument){
			if(!$argument instanceof LiteralArgument){
				$values[] = $argument;
			}
		}
		if(count($rest) !== count($values)){
			throw new \LogicException("$where must take the sender and " . count($values) . " arguments, or the sender and CommandArgs");
		}
		foreach($values as $index => $argument){
			$parameter = $rest[$index];
			$name = $argument->getName();
			if($parameter->isVariadic() || $parameter->isPassedByReference()){
				throw new \LogicException("$where cannot take \$" . $parameter->getName() . " by reference or variadically");
			}
			if($argument->isOptional() && !$parameter->isDefaultValueAvailable()){
				throw new \LogicException("$where needs a default value for the optional argument \"$name\"");
			}
			if(!self::accepts($parameter->getType(), $argument->getValueType())){
				throw new \LogicException("$where cannot take argument \"$name\" of type " . $argument->getValueType() . " as \$" . $parameter->getName());
			}
		}
		return new self(self::TYPED, $senderClass);
	}

	private static function senderClass(?\ReflectionType $type, string $where) : ?string{
		if($type === null){
			return null;
		}
		if($type instanceof \ReflectionNamedType){
			$name = $type->getName();
			if(in_array($name, ["mixed", "object"], true) || is_a(CommandSender::class, $name, true)){
				return null;
			}
			if(!$type->isBuiltin() && is_a($name, CommandSender::class, true)){
				return $name;
			}
		}
		throw new \LogicException("$where must take a CommandSender as its first parameter");
	}

	private static function isNamed(?\ReflectionType $type, string $class) : bool{
		return $type instanceof \ReflectionNamedType && !$type->isBuiltin() && is_a($class, $type->getName(), true);
	}

	private static function accepts(?\ReflectionType $type, string $valueType) : bool{
		if($type === null || $valueType === "mixed"){
			return true;
		}
		if($type instanceof \ReflectionUnionType){
			foreach($type->getTypes() as $member){
				if(self::accepts($member, $valueType)){
					return true;
				}
			}
			return false;
		}
		if(!$type instanceof \ReflectionNamedType){
			return false;
		}
		$name = $type->getName();
		if($name === "mixed"){
			return true;
		}
		if($type->isBuiltin()){
			return match($name){
				"float" => $valueType === "float" || $valueType === "int",
				"object" => !in_array($valueType, ["int", "float", "string", "bool", "array"], true),
				default => $name === $valueType
			};
		}
		return is_a($valueType, $name, true);
	}
}
