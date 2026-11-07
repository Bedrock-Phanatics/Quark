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
use quark\command\argument\Argument;
use quark\command\argument\ArgumentKind;
use quark\command\Command;
use quark\command\SimpleCommandMap;
use quark\lang\Translatable;
use quark\player\Player;
use function array_values;
use function count;
use function implode;
use function in_array;
use function mb_strcut;
use function strlen;
use function strtolower;
use function ucfirst;

/**
 * @internal
 */
final class CommandMetadataAdapter{
	public const int MAX_COMMAND_TEXT_BYTES = 1000;

	/** @phpstan-var array<string, CommandHardEnum> */
	private array $enums = [];
	/** @phpstan-var array<string, true> */
	private array $enumNames = [];

	private function __construct(
		private Player $player
	){}

	/**
	 * @param Command[] $commands
	 * @phpstan-param array<string, Command> $commands
	 */
	public static function createPacket(array $commands, Player $player) : AvailableCommandsPacket{
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
			$aliasEnum = $this->getEnum(ucfirst($label) . "Aliases", $aliases);
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
				$parameters[] = CommandParameter::enum($literal, $this->getEnum("literal_" . $literal, [$literal]), 0);
			}
			foreach($syntax->getArguments() as $argument){
				$parameters[] = $this->createParameter($argument);
			}
			$overloads[] = new CommandOverload(chaining: false, parameters: $parameters);
		}
		return $overloads;
	}

	/**
	 * @phpstan-param Argument<mixed> $argument
	 */
	private function createParameter(Argument $argument) : CommandParameter{
		$name = $argument->getName();
		$optional = $argument->isOptional();
		return match($argument->getKind()){
			ArgumentKind::WORD => CommandParameter::standard($name, AvailableCommandsPacket::ARG_TYPE_STRING, 0, $optional),
			ArgumentKind::INTEGER => CommandParameter::standard($name, AvailableCommandsPacket::ARG_TYPE_INT, 0, $optional),
			ArgumentKind::FLOAT => CommandParameter::standard($name, AvailableCommandsPacket::ARG_TYPE_FLOAT, 0, $optional),
			ArgumentKind::TEXT => CommandParameter::standard($name, AvailableCommandsPacket::ARG_TYPE_RAWTEXT, 0, $optional),
			ArgumentKind::PLAYER => CommandParameter::standard($name, AvailableCommandsPacket::ARG_TYPE_TARGET, 0, $optional),
			ArgumentKind::ENUM => CommandParameter::enum($name, $this->getEnum($name, $argument->getEnumValues()), 0, $optional),
		};
	}

	/**
	 * @param string[] $values
	 * @phpstan-param list<string> $values
	 */
	private function getEnum(string $name, array $values) : CommandHardEnum{
		$key = implode(",", $values);
		if(isset($this->enums[$key])){
			return $this->enums[$key];
		}
		//names stay unique even when commands reuse one for different values
		if(isset($this->enumNames[$name])){
			$name .= "#" . count($this->enums);
		}
		$this->enumNames[$name] = true;
		return $this->enums[$key] = new CommandHardEnum($name, $values);
	}
}
