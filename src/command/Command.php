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

/**
 * Command handling related classes
 */
namespace quark\command;

use quark\command\argument\Argument;
use quark\command\internal\SyntaxMatcher;
use quark\command\utils\CommandException;
use quark\lang\KnownTranslationFactory;
use quark\lang\Translatable;
use quark\permission\PermissionManager;
use quark\pulse\internal\PulseZones;
use quark\pulse\PulseZone;
use quark\Server;
use quark\utils\BroadcastLoggerForwarder;
use quark\utils\TextFormat;
use function array_values;
use function explode;
use function implode;
use function str_replace;
use const PHP_INT_MAX;

/**
 * @phpstan-import-type PreparedSyntaxes from SyntaxMatcher
 */
abstract class Command{

	private string $name;
	private ?PulseZone $pulse = null;

	private string $nextLabel;
	private string $label;

	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $aliases = [];

	/**
	 * @var string[]
	 * @phpstan-var list<string>
	 */
	private array $activeAliases = [];

	private ?CommandMap $commandMap = null;

	protected Translatable|string $description = "";

	protected Translatable|string $usageMessage;

	/** @var string[] */
	private array $permission = [];
	private ?string $permissionMessage = null;

	/**
	 * @var CommandSyntax[]
	 * @phpstan-var array<string, CommandSyntax>
	 */
	private array $syntaxes = [];
	/**
	 * @var Subcommand[]
	 * @phpstan-var array<string, Subcommand>
	 */
	private array $subcommands = [];
	/** @phpstan-var PreparedSyntaxes|null */
	private ?array $preparedSyntaxes = null;

	/**
	 * @param string[] $aliases
	 * @phpstan-param list<string> $aliases
	 */
	public function __construct(string $name, Translatable|string $description = "", Translatable|string|null $usageMessage = null, array $aliases = []){
		$this->name = $name;
		$this->setLabel($name);
		$this->setDescription($description);
		$this->usageMessage = $usageMessage ?? ("/" . $name);
		$this->setAliases($aliases);
	}

	/**
	 * @param string[] $args
	 * @phpstan-param list<string> $args
	 *
	 * @return mixed
	 * @throws CommandException
	 */
	public function execute(CommandSender $sender, string $commandLabel, array $args){
		if($this->preparedSyntaxes === null){
			throw new \LogicException("Command \"$this->name\" must be registered in a command map before its syntaxes can be executed");
		}
		$parsed = SyntaxMatcher::parse($this, $this->preparedSyntaxes, $sender, $commandLabel, $args);
		if($parsed !== null){
			$subcommand = $this->subcommands[$parsed->subcommand()] ?? null;
			if($subcommand !== null){
				$subcommand->dispatch($sender, $parsed);
			}else{
				$this->run($sender, $parsed);
			}
		}
		return null;
	}

	/**
	 * @throws \LogicException
	 */
	protected function run(CommandSender $sender, CommandArgs $args) : void{
		throw new \LogicException("Command \"$this->name\" must override run()");
	}

	/**
	 * @phpstan-param Argument<mixed> $argument
	 * @throws \InvalidArgumentException
	 * @throws \LogicException
	 */
	public function registerArgument(Argument $argument) : void{
		$this->rootSyntax()->registerArgument($argument);
	}

	/**
	 * @param Argument[] $arguments
	 * @phpstan-param list<Argument<mixed>> $arguments
	 * @throws \InvalidArgumentException
	 * @throws \LogicException
	 */
	public function registerArguments(array $arguments) : void{
		$this->rootSyntax()->registerArguments($arguments);
	}

	private function rootSyntax() : CommandSyntax{
		$this->checkNotRegistered();
		return $this->syntaxes[""] ??= new CommandSyntax("");
	}

	private function checkNotRegistered() : void{
		if($this->isRegistered()){
			throw new \LogicException("Cannot declare syntaxes of command \"$this->name\" while it is registered");
		}
	}

	/**
	 * @throws \InvalidArgumentException
	 * @throws \LogicException
	 */
	public function syntax(string $path = "") : CommandSyntax{
		$this->checkNotRegistered();
		$syntax = new CommandSyntax($path);
		$this->addSyntax($syntax);
		return $syntax;
	}

	/**
	 * @throws \InvalidArgumentException
	 * @throws \LogicException
	 */
	public function registerSubcommand(Subcommand $subcommand) : void{
		$this->checkNotRegistered();
		$syntax = $subcommand->getSyntax();
		$this->addSyntax($syntax);
		$this->subcommands[$syntax->getPath()] = $subcommand;
	}

	private function addSyntax(CommandSyntax $syntax) : void{
		$path = $syntax->getPath();
		if(isset($this->syntaxes[$path])){
			throw new \InvalidArgumentException("Command \"$this->name\" already declares the syntax \"$path\"");
		}
		$this->syntaxes[$path] = $syntax;
	}

	/**
	 * @return CommandSyntax[]
	 * @phpstan-return array<string, CommandSyntax>
	 */
	public function getSyntaxes() : array{
		return $this->syntaxes;
	}

	/**
	 * @internal
	 * @phpstan-param PreparedSyntaxes|null $prepared
	 */
	public function setPreparedSyntaxes(?array $prepared) : void{
		$this->preparedSyntaxes = $prepared;
		$this->markSyntaxes(true);
	}

	private function markSyntaxes(bool $registered) : void{
		foreach($this->syntaxes as $syntax){
			$syntax->markRegistered($registered);
		}
	}

	/**
	 * @internal
	 * @return Subcommand[]
	 * @phpstan-return array<string, Subcommand>
	 */
	public function getSubcommands() : array{
		return $this->subcommands;
	}

	/**
	 * @internal
	 * @param string[] $args
	 * @phpstan-param list<string> $args
	 */
	public function canReachPermittedSyntax(CommandSender $sender, array $args, bool $moreArgs) : bool{
		return $this->preparedSyntaxes === null || SyntaxMatcher::canReachPermittedSyntax($this->preparedSyntaxes, $sender, $args, $moreArgs);
	}

	/**
	 * @internal
	 * @return CommandSyntax[]|null
	 * @phpstan-return array<string, CommandSyntax>|null
	 */
	public function getPreparedSyntaxes() : ?array{
		return $this->preparedSyntaxes["routes"] ?? null;
	}

	public function getName() : string{
		return $this->name;
	}

	/** @internal */
	public function getPulseZone() : PulseZone{
		return $this->pulse ??= PulseZones::dynamic("command." . $this->name);
	}

	/**
	 * @return string[]
	 */
	public function getPermissions() : array{
		return $this->permission;
	}

	/**
	 * @param string[] $permissions
	 */
	public function setPermissions(array $permissions) : void{
		$permissionManager = PermissionManager::getInstance();
		foreach($permissions as $perm){
			if($permissionManager->getPermission($perm) === null){
				throw new \InvalidArgumentException("Cannot use non-existing permission \"$perm\"");
			}
		}
		$this->permission = $permissions;
		$this->invalidateClientMetadata();
	}

	public function setPermission(?string $permission) : void{
		$this->setPermissions($permission === null ? [] : explode(";", $permission, limit: PHP_INT_MAX));
	}

	public function testPermission(CommandSender $target, ?string $permission = null) : bool{
		if($this->testPermissionSilent($target, $permission)){
			return true;
		}

		if($this->permissionMessage === null){
			$target->sendMessage(KnownTranslationFactory::quark_command_error_permission($this->name)->prefix(TextFormat::RED));
		}elseif($this->permissionMessage !== ""){
			$target->sendMessage(str_replace("<permission>", $permission ?? implode(";", $this->permission), $this->permissionMessage));
		}

		return false;
	}

	public function testPermissionSilent(CommandSender $target, ?string $permission = null) : bool{
		$list = $permission !== null ? [$permission] : $this->permission;
		foreach($list as $p){
			if($target->hasPermission($p)){
				return true;
			}
		}

		return false;
	}

	public function getLabel() : string{
		return $this->label;
	}

	public function setLabel(string $name) : bool{
		$this->nextLabel = $name;
		if(!$this->isRegistered()){
			$this->label = $name;

			return true;
		}

		return false;
	}

	/**
	 * Registers the command into a Command map
	 */
	public function register(CommandMap $commandMap) : bool{
		if($this->allowChangesFrom($commandMap)){
			$this->commandMap = $commandMap;

			return true;
		}

		return false;
	}

	public function unregister(CommandMap $commandMap) : bool{
		if($this->allowChangesFrom($commandMap)){
			$this->commandMap = null;
			$this->activeAliases = $this->aliases;
			$this->label = $this->nextLabel;
			if($this->preparedSyntaxes !== null){
				$this->preparedSyntaxes = null;
				$this->markSyntaxes(false);
			}

			return true;
		}

		return false;
	}

	private function allowChangesFrom(CommandMap $commandMap) : bool{
		return $this->commandMap === null || $this->commandMap === $commandMap;
	}

	public function isRegistered() : bool{
		return $this->commandMap !== null;
	}

	/**
	 * @return string[]
	 * @phpstan-return list<string>
	 */
	public function getAliases() : array{
		return $this->activeAliases;
	}

	public function getPermissionMessage() : ?string{
		return $this->permissionMessage;
	}

	public function getDescription() : Translatable|string{
		return $this->description;
	}

	public function getUsage() : Translatable|string{
		return $this->usageMessage;
	}

	/**
	 * @param string[] $aliases
	 * @phpstan-param list<string> $aliases
	 */
	public function setAliases(array $aliases) : void{
		$aliases = array_values($aliases); //because plugins can and will pass crap
		$this->aliases = $aliases;
		if(!$this->isRegistered()){
			$this->activeAliases = $aliases;
		}
	}

	public function setDescription(Translatable|string $description) : void{
		$this->description = $description;
		$this->invalidateClientMetadata();
	}

	private function invalidateClientMetadata() : void{
		if($this->commandMap instanceof SimpleCommandMap){
			$this->commandMap->invalidateClientMetadata();
		}
	}

	public function setPermissionMessage(string $permissionMessage) : void{
		$this->permissionMessage = $permissionMessage;
	}

	public function setUsage(Translatable|string $usage) : void{
		$this->usageMessage = $usage;
	}

	public static function broadcastCommandMessage(CommandSender $source, Translatable|string $message, bool $sendToSource = true) : void{
		$users = $source->getServer()->getBroadcastChannelSubscribers(Server::BROADCAST_CHANNEL_ADMINISTRATIVE);
		$result = KnownTranslationFactory::chat_type_admin($source->getName(), $message);
		$colored = $result->prefix(TextFormat::GRAY . TextFormat::ITALIC);

		if($sendToSource){
			$source->sendMessage($message);
		}

		foreach($users as $user){
			if($user instanceof BroadcastLoggerForwarder){
				$user->sendMessage($result);
			}elseif($user !== $source){
				$user->sendMessage($colored);
			}
		}
	}

	public function __toString() : string{
		return $this->name;
	}
}
