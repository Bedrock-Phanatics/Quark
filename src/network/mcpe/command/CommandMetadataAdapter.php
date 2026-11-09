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

namespace quark\network\mcpe\command;

use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\serializer\AvailableCommandsPacketAssembler;
use pocketmine\network\mcpe\protocol\types\command\CommandData;
use pocketmine\network\mcpe\protocol\types\command\CommandHardEnum;
use pocketmine\network\mcpe\protocol\types\command\CommandOverload;
use pocketmine\network\mcpe\protocol\types\command\CommandParameter;
use pocketmine\network\mcpe\protocol\types\command\CommandPermissions;
use pocketmine\network\mcpe\protocol\types\command\CommandSoftEnum;
use quark\command\argument\Argument;
use quark\command\argument\ArgumentKind;
use quark\command\argument\CommandEnum;
use quark\command\argument\LiteralArgument;
use quark\command\Command;
use quark\command\SimpleCommandMap;
use quark\lang\Translatable;
use quark\player\Player;
use function array_push;
use function array_values;
use function count;
use function in_array;
use function mb_strcut;
use function spl_object_id;
use function strlen;
use function strtolower;
use function ucfirst;

/**
 * @internal
 */
final class CommandMetadataAdapter{
	public const int MAX_COMMAND_TEXT_BYTES = 1000;

	/** @phpstan-var array<int, CommandHardEnum> */
	private array $enums = [];
	/** @phpstan-var array<string, CommandHardEnum> */
	private array $literalEnums = [];
	/** @phpstan-var array<string, true> */
	private array $enumNames = [];
	/** @phpstan-var array<string, CommandSoftEnum> */
	private array $softEnums = [];
	/** @phpstan-var array<string, int> */
	private array $softEnumVersions = [];

	private function __construct(
		private Player $player
	){}

	/**
	 * @param Command[] $commands
	 * @phpstan-param array<string, Command> $commands
	 * @param int[] $softEnumVersions filled with the version of every soft enum the packet carries
	 * @phpstan-param array<string, int> $softEnumVersions
	 */
	public static function createPacket(array $commands, Player $player, array &$softEnumVersions = []) : AvailableCommandsPacket{
		$adapter = new self($player);
		$commandData = [];
		foreach($commands as $command){
			$label = $command->getLabel();
			if(
				isset($commandData[$label]) ||
				//a server alias can take over the label
				($commands[$label] ?? null) !== $command ||
				$label === "help" ||
				strlen($label) > self::MAX_COMMAND_TEXT_BYTES ||
				!SimpleCommandMap::isOwnerEnabled($command) ||
				!$command->testPermissionSilent($player)
			){
				continue;
			}
			$data = $adapter->createCommandData($command, $commands);
			if($data !== null){
				$commandData[$label] = $data;
			}
		}

		$softEnumVersions = $adapter->softEnumVersions;
		return AvailableCommandsPacketAssembler::assemble(array_values($commandData), [], []);
	}

	/**
	 * @param Command[] $commands
	 * @phpstan-param array<string, Command> $commands
	 */
	private function createCommandData(Command $command, array $commands) : ?CommandData{
		$overloads = $this->createOverloads($command);
		if(count($overloads) === 0){
			return null;
		}

		$label = $command->getLabel();
		$lname = strtolower($label);
		$aliases = [];
		foreach($command->getAliases() as $alias){
			//server aliases can take over an alias after registration
			if(($commands[$alias] ?? null) === $command){
				$aliases[] = $alias;
			}
		}
		$aliasEnum = null;
		if(count($aliases) > 0){
			if(!in_array($lname, $aliases, true)){
				//work around a client bug which makes the original name not show when aliases are used
				$aliases[] = $lname;
			}
			$aliasEnum = new CommandHardEnum($this->uniqueName(ucfirst($label) . "Aliases"), $aliases);
		}

		$description = $command->getDescription();
		if($description instanceof Translatable){
			$description = $this->player->getLanguage()->translate($description);
		}
		return new CommandData(
			$lname, //TODO: commands containing uppercase letters in the name crash 1.9.0 client
			strlen($description) > self::MAX_COMMAND_TEXT_BYTES ? mb_strcut($description, 0, self::MAX_COMMAND_TEXT_BYTES, "UTF-8") : $description,
			0,
			CommandPermissions::NORMAL,
			$aliasEnum,
			$overloads,
			chainedSubCommandData: []
		);
	}

	/**
	 * @return CommandOverload[]
	 * @phpstan-return list<CommandOverload>
	 */
	private function createOverloads(Command $command) : array{
		$syntaxes = $command->getPreparedSyntaxes();
		if($syntaxes === null){
			//every legacy command has one free-form overload
			return [new CommandOverload(chaining: false, parameters: [CommandParameter::standard("args", AvailableCommandsPacket::ARG_TYPE_RAWTEXT, 0, true)])];
		}

		$overloads = [];
		foreach($syntaxes as $syntax){
			if(!$syntax->testPermissionSilent($this->player)){
				continue;
			}
			$parameters = [];
			foreach($syntax->getLiterals() as $literal){
				$parameters[] = $this->createLiteralParameter($literal);
			}
			foreach($syntax->getArguments() as $argument){
				array_push($parameters, ...$this->createParameters($argument));
			}
			$overloads[] = new CommandOverload(chaining: false, parameters: $parameters);
		}
		return $overloads;
	}

	private function createLiteralParameter(string $literal) : CommandParameter{
		$enum = $this->literalEnums[$literal] ??= new CommandHardEnum($this->uniqueName("literal_" . $literal), [$literal]);
		return CommandParameter::enum($literal, $enum, 0);
	}

	/**
	 * @phpstan-param Argument<mixed> $argument
	 * @return CommandParameter[]
	 * @phpstan-return list<CommandParameter>
	 */
	private function createParameters(Argument $argument) : array{
		$name = $argument->getName();
		$optional = $argument->isOptional();
		if($argument instanceof LiteralArgument){
			return [$this->createLiteralParameter($argument->getLiteral())];
		}
		switch($argument->getKind()){
			case ArgumentKind::WORD:
				return [CommandParameter::standard($name, AvailableCommandsPacket::ARG_TYPE_STRING, 0, $optional)];
			case ArgumentKind::INTEGER:
				return [CommandParameter::standard($name, AvailableCommandsPacket::ARG_TYPE_INT, 0, $optional)];
			case ArgumentKind::FLOAT:
				return [CommandParameter::standard($name, AvailableCommandsPacket::ARG_TYPE_FLOAT, 0, $optional)];
			case ArgumentKind::TEXT:
				return [CommandParameter::standard($name, AvailableCommandsPacket::ARG_TYPE_RAWTEXT, 0, $optional)];
			case ArgumentKind::PLAYER:
				return [CommandParameter::standard($name, AvailableCommandsPacket::ARG_TYPE_TARGET, 0, $optional)];
			case ArgumentKind::POSITION:
				return [CommandParameter::standard($name, AvailableCommandsPacket::ARG_TYPE_POSITION, 0, $optional)];
			case ArgumentKind::VALUE:
				//values are sent as plain numbers that clients let you prefix with ~
				if($argument->getWidth() === 2){
					return [
						CommandParameter::standard("yaw", AvailableCommandsPacket::ARG_TYPE_VALUE, 0, $optional),
						CommandParameter::standard("pitch", AvailableCommandsPacket::ARG_TYPE_VALUE, 0, $optional)
					];
				}
				return [CommandParameter::standard($name, AvailableCommandsPacket::ARG_TYPE_VALUE, 0, $optional)];
			case ArgumentKind::ENUM:
				$enum = $argument->getEnum();
				return [$enum !== null ?
					CommandParameter::enum($name, $this->getEnum($enum), 0, $optional) :
					CommandParameter::standard($name, AvailableCommandsPacket::ARG_TYPE_STRING, 0, $optional)];
			case ArgumentKind::SOFT_ENUM:
				$softEnum = $argument->getSoftEnum();
				if($softEnum === null){
					return [CommandParameter::standard($name, AvailableCommandsPacket::ARG_TYPE_STRING, 0, $optional)];
				}
				$enumName = $softEnum->getName();
				if(!isset($this->softEnums[$enumName])){
					$this->softEnums[$enumName] = new CommandSoftEnum($enumName, $softEnum->getValues());
					$this->softEnumVersions[$enumName] = $softEnum->getVersion();
				}
				return [CommandParameter::softEnum($name, $this->softEnums[$enumName], 0, $optional)];
			case ArgumentKind::LITERAL:
				break;
		}
		throw new \LogicException("Unhandled argument kind");
	}

	private function getEnum(CommandEnum $enum) : CommandHardEnum{
		return $this->enums[spl_object_id($enum)] ??= new CommandHardEnum($this->uniqueName($enum->getName()), $enum->getValues());
	}

	//the client identifies enums by name, so distinct enums that share one need to be told apart
	private function uniqueName(string $name) : string{
		$unique = $name;
		for($i = 2; isset($this->enumNames[$unique]); ++$i){
			$unique = $name . "#" . $i;
		}
		$this->enumNames[$unique] = true;
		return $unique;
	}
}
