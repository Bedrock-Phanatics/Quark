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

use quark\command\defaults\BanCommand;
use quark\command\defaults\BanIpCommand;
use quark\command\defaults\BanListCommand;
use quark\command\defaults\ClearCommand;
use quark\command\defaults\DefaultGamemodeCommand;
use quark\command\defaults\DeopCommand;
use quark\command\defaults\DifficultyCommand;
use quark\command\defaults\DumpMemoryCommand;
use quark\command\defaults\EffectCommand;
use quark\command\defaults\EnchantCommand;
use quark\command\defaults\GamemodeCommand;
use quark\command\defaults\GarbageCollectorCommand;
use quark\command\defaults\GiveCommand;
use quark\command\defaults\HelpCommand;
use quark\command\defaults\KickCommand;
use quark\command\defaults\KillCommand;
use quark\command\defaults\ListCommand;
use quark\command\defaults\MeCommand;
use quark\command\defaults\OpCommand;
use quark\command\defaults\PardonCommand;
use quark\command\defaults\PardonIpCommand;
use quark\command\defaults\ParticleCommand;
use quark\command\defaults\PluginsCommand;
use quark\command\defaults\PulseCommand;
use quark\command\defaults\SaveCommand;
use quark\command\defaults\SaveOffCommand;
use quark\command\defaults\SaveOnCommand;
use quark\command\defaults\SayCommand;
use quark\command\defaults\SeedCommand;
use quark\command\defaults\SetWorldSpawnCommand;
use quark\command\defaults\SpawnpointCommand;
use quark\command\defaults\StatusCommand;
use quark\command\defaults\StopCommand;
use quark\command\defaults\TeleportCommand;
use quark\command\defaults\TellCommand;
use quark\command\defaults\TimeCommand;
use quark\command\defaults\TitleCommand;
use quark\command\defaults\TransferServerCommand;
use quark\command\defaults\VanillaCommand;
use quark\command\defaults\VersionCommand;
use quark\command\defaults\WeatherCommand;
use quark\command\defaults\WhitelistCommand;
use quark\command\defaults\WorldCommand;
use quark\command\defaults\XpCommand;
use quark\command\internal\CommandInputException;
use quark\command\internal\SyntaxMatcher;
use quark\command\utils\CommandStringHelper;
use quark\command\utils\InvalidCommandSyntaxException;
use quark\lang\KnownTranslationFactory;
use quark\lang\Translatable;
use quark\plugin\Plugin;
use quark\plugin\PluginOwned;
use quark\Server;
use quark\utils\TextFormat;
use quark\utils\Utils;
use function array_shift;
use function array_values;
use function count;
use function implode;
use function str_contains;
use function strcasecmp;
use function strtolower;
use function trim;

class SimpleCommandMap implements CommandMap{

	/**
	 * @var Command[]
	 * @phpstan-var array<string, Command>
	 */
	protected array $knownCommands = [];

	private bool $clientMetadataDirty = false;

	public function __construct(private Server $server){
		$this->setDefaultCommands();
	}

	private function setDefaultCommands() : void{
		$this->registerAll("quark", [
			new BanCommand(),
			new BanIpCommand(),
			new BanListCommand(),
			new ClearCommand(),
			new DefaultGamemodeCommand(),
			new DeopCommand(),
			new DifficultyCommand(),
			new DumpMemoryCommand(),
			new EffectCommand(),
			new EnchantCommand(),
			new GamemodeCommand(),
			new GarbageCollectorCommand(),
			new GiveCommand(),
			new HelpCommand(),
			new KickCommand(),
			new KillCommand(),
			new ListCommand(),
			new MeCommand(),
			new OpCommand(),
			new PardonCommand(),
			new PardonIpCommand(),
			new ParticleCommand(),
			new PluginsCommand(),
			new SaveCommand(),
			new SaveOffCommand(),
			new SaveOnCommand(),
			new SayCommand(),
			new SeedCommand(),
			new SetWorldSpawnCommand(),
			new SpawnpointCommand(),
			new StatusCommand(),
			new StopCommand(),
			new TeleportCommand(),
			new TellCommand(),
			new TimeCommand(),
			new PulseCommand(),
			new TitleCommand(),
			new TransferServerCommand(),
			new VersionCommand(),
			new WeatherCommand(),
			new WhitelistCommand(),
			new WorldCommand(),
			new XpCommand(),
		]);
	}

	public function registerAll(string $fallbackPrefix, array $commands) : void{
		foreach($commands as $command){
			$this->register($fallbackPrefix, $command);
		}
	}

	/**
	 * @throws \InvalidArgumentException
	 * @throws \LogicException
	 */
	public function register(string $fallbackPrefix, Command $command, ?string $label = null) : bool{
		if(count($command->getPermissions()) === 0){
			throw new \InvalidArgumentException("Commands must have a permission set");
		}
		if($command->isRegistered()){
			throw new \InvalidArgumentException("Command \"" . $command->getName() . "\" is already registered in a command map");
		}

		if($label === null){
			$label = $command->getLabel();
		}
		$label = trim($label);
		$fallbackPrefix = strtolower(trim($fallbackPrefix));

		$prepared = SyntaxMatcher::prepare($command);

		$previousCommands = $this->knownCommands;
		$previousLabel = $command->getLabel();
		$previousAliases = $command->getAliases();
		$success = false;
		try{
			$registered = $this->registerAlias($command, false, $fallbackPrefix, $label);

			$aliases = $command->getAliases();
			foreach($aliases as $index => $alias){
				if(!$this->registerAlias($command, true, $fallbackPrefix, $alias)){
					unset($aliases[$index]);
				}
			}
			$command->setAliases(array_values($aliases));

			if(!$registered){
				$command->setLabel($fallbackPrefix . ":" . $label);
			}

			$success = $command->register($this);
		}finally{
			if(!$success){
				$this->knownCommands = $previousCommands;
				//an override may fail after parent::register() already bound the command to this map
				if($command->isRegistered()){
					$command->unregister($this);
				}
				$command->setLabel($previousLabel);
				$command->setAliases($previousAliases);
			}
		}
		if(!$success){
			return false;
		}

		if($prepared !== null){
			$command->setPreparedSyntaxes($prepared);
		}
		$this->clientMetadataDirty = true;

		return $registered;
	}

	public function unregister(Command $command) : bool{
		foreach(Utils::promoteKeys($this->knownCommands) as $lbl => $cmd){
			if($cmd === $command){
				unset($this->knownCommands[$lbl]);
			}
		}

		$command->unregister($this);
		$this->clientMetadataDirty = true;

		return true;
	}

	/**
	 * @internal
	 */
	public function unregisterPluginSyntaxCommands(Plugin $plugin) : void{
		foreach($this->knownCommands as $command){
			if($command instanceof PluginOwned && $command->getOwningPlugin() === $plugin && $command->getPreparedSyntaxes() !== null){
				$this->unregister($command);
			}
		}
	}

	private function registerAlias(Command $command, bool $isAlias, string $fallbackPrefix, string $label) : bool{
		$this->knownCommands[$fallbackPrefix . ":" . $label] = $command;
		if(($command instanceof VanillaCommand || $isAlias) && isset($this->knownCommands[$label])){
			return false;
		}

		if(isset($this->knownCommands[$label]) && $this->knownCommands[$label]->getLabel() === $label){
			return false;
		}

		if(!$isAlias){
			$command->setLabel($label);
		}

		$this->knownCommands[$label] = $command;

		return true;
	}

	public function dispatch(CommandSender $sender, string $commandLine) : bool{
		$args = CommandStringHelper::parseQuoteAware($commandLine);

		$sentCommandLabel = array_shift($args);
		if($sentCommandLabel === null){
			$sender->sendMessage(KnownTranslationFactory::quark_command_notFound("", "/help")->prefix(TextFormat::RED));
			return false;
		}
		return $this->dispatchParsed($sender, $sentCommandLabel, $args);
	}

	/**
	 * @param string[] $args
	 * @phpstan-param list<string> $args
	 */
	public function dispatchParsed(CommandSender $sender, string $commandLabel, array $args) : bool{
		$target = $this->getCommand($commandLabel);
		if($target === null){
			$sender->sendMessage(KnownTranslationFactory::quark_command_notFound($commandLabel, "/help")->prefix(TextFormat::RED));
			return false;
		}

		$pulse = $target->getPulseZone();
		$scope = $pulse->start();

		try{
			if(self::isOwnerEnabled($target) && $target->testPermission($sender)){
				$target->execute($sender, $commandLabel, $args);
			}
		}catch(CommandInputException $e){
			$message = $e->getUserMessage();
			if($message !== null){
				$sender->sendMessage($message instanceof Translatable ? $message->prefix(TextFormat::RED) : TextFormat::RED . $message);
			}
			foreach($e->getUsages() as $usage){
				$sender->sendMessage($sender->getLanguage()->translate(KnownTranslationFactory::commands_generic_usage($usage)));
			}
		}catch(InvalidCommandSyntaxException $e){
			$sender->sendMessage($sender->getLanguage()->translate(KnownTranslationFactory::commands_generic_usage($target->getUsage())));
		}finally{
			$pulse->stop($scope);
		}
		return true;
	}

	/**
	 * @internal
	 */
	public static function isOwnerEnabled(Command $command) : bool{
		return !$command instanceof PluginOwned || $command->getOwningPlugin()->isEnabled();
	}

	public function clearCommands() : void{
		foreach($this->knownCommands as $command){
			$command->unregister($this);
		}
		$this->knownCommands = [];
		$this->setDefaultCommands();
		$this->clientMetadataDirty = true;
	}

	/**
	 * @internal
	 */
	public function invalidateClientMetadata() : void{
		$this->clientMetadataDirty = true;
	}

	/**
	 * @internal
	 */
	public function consumeClientMetadataChanges() : bool{
		$dirty = $this->clientMetadataDirty;
		$this->clientMetadataDirty = false;
		return $dirty;
	}

	public function getCommand(string $name) : ?Command{
		return $this->knownCommands[$name] ?? null;
	}

	/**
	 * @return Command[]
	 * @phpstan-return array<string, Command>
	 */
	public function getCommands() : array{
		return $this->knownCommands;
	}

	public function registerServerAliases() : void{
		$values = $this->server->getCommandAliases();

		foreach(Utils::stringifyKeys($values) as $alias => $commandStrings){
			if(str_contains($alias, ":")){
				$this->server->getLogger()->warning($this->server->getLanguage()->translate(KnownTranslationFactory::quark_command_alias_illegal($alias)));
				continue;
			}

			$targets = [];
			$bad = [];
			$recursive = [];

			foreach($commandStrings as $commandString){
				$args = CommandStringHelper::parseQuoteAware($commandString);
				$commandName = array_shift($args) ?? "";
				$command = $this->getCommand($commandName);

				if($command === null){
					$bad[] = $commandString;
				}elseif(strcasecmp($commandName, $alias) === 0){
					$recursive[] = $commandString;
				}else{
					$targets[] = $commandString;
				}
			}

			if(count($recursive) > 0){
				$this->server->getLogger()->warning($this->server->getLanguage()->translate(KnownTranslationFactory::quark_command_alias_recursive($alias, implode(", ", $recursive))));
				continue;
			}

			if(count($bad) > 0){
				$this->server->getLogger()->warning($this->server->getLanguage()->translate(KnownTranslationFactory::quark_command_alias_notFound($alias, implode(", ", $bad))));
				continue;
			}

			//These registered commands have absolute priority
			$lowerAlias = strtolower($alias);
			if(count($targets) > 0){
				$this->knownCommands[$lowerAlias] = new FormattedCommandAlias($lowerAlias, $targets);
			}else{
				unset($this->knownCommands[$lowerAlias]);
			}

		}
		$this->clientMetadataDirty = true;
	}
}
