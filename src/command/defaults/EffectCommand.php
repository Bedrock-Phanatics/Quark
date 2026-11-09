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

namespace quark\command\defaults;

use pocketmine\utils\Limits;
use quark\command\argument\ArgumentParseException;
use quark\command\argument\BooleanArgument;
use quark\command\argument\IntegerArgument;
use quark\command\argument\MappedArgument;
use quark\command\argument\PlayerArgument;
use quark\command\argument\StringArgument;
use quark\command\CommandSender;
use quark\entity\effect\Effect;
use quark\entity\effect\EffectInstance;
use quark\entity\effect\StringToEffectParser;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\utils\TextFormat;
use function count;
use function intdiv;
use function strtolower;

class EffectCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"effect",
			KnownTranslationFactory::quark_command_effect_description()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_EFFECT_SELF,
			DefaultPermissionNames::COMMAND_EFFECT_OTHER
		]);

		//a plain word here, since "clear" and effect names cannot be told apart by a declared syntax
		$this->syntax()
			->registerArguments([self::target(), new StringArgument("effect")])
			->handler($this->byName(...));
		$this->syntax()
			->registerArguments([self::target(), self::effect(), new IntegerArgument("seconds", 0, intdiv(Limits::INT32_MAX, 20))])
			->registerArguments(self::options())
			->handler($this->timed(...));
		$this->syntax()
			->registerArguments([self::target(), self::effect()])
			->literal("infinite")
			->registerArguments(self::options())
			->handler($this->infinite(...));
	}

	private static function target() : PlayerArgument{
		return new PlayerArgument("target", partial: true, self: true);
	}

	/**
	 * @return MappedArgument<Effect>
	 */
	private static function effect() : MappedArgument{
		return new MappedArgument(
			"effect",
			static fn(string $name) : Effect => StringToEffectParser::getInstance()->parse($name) ?? throw new ArgumentParseException(KnownTranslationFactory::commands_effect_notFound($name)),
			Effect::class
		);
	}

	/**
	 * @return array{IntegerArgument, BooleanArgument}
	 */
	private static function options() : array{
		return [new IntegerArgument("amplifier", 0, 255, optional: true), new BooleanArgument("hideParticles", optional: true)];
	}

	private function byName(CommandSender $sender, Player $target, string $name) : void{
		if(!$this->canTarget($sender, $target, DefaultPermissionNames::COMMAND_EFFECT_SELF, DefaultPermissionNames::COMMAND_EFFECT_OTHER)){
			return;
		}
		if(strtolower($name) === "clear"){
			$target->getEffects()->clear();
			$sender->sendMessage(KnownTranslationFactory::commands_effect_success_removed_all($target->getDisplayName()));
			return;
		}

		$effect = StringToEffectParser::getInstance()->parse($name);
		if($effect === null){
			$sender->sendMessage(KnownTranslationFactory::commands_effect_notFound($name)->prefix(TextFormat::RED));
			return;
		}
		$this->apply($sender, $target, $effect, null, false, 0, false);
	}

	private function timed(CommandSender $sender, Player $target, Effect $effect, int $seconds, int $amplifier = 0, bool $hideParticles = false) : void{
		if($this->canTarget($sender, $target, DefaultPermissionNames::COMMAND_EFFECT_SELF, DefaultPermissionNames::COMMAND_EFFECT_OTHER)){
			$this->apply($sender, $target, $effect, $seconds, false, $amplifier, $hideParticles);
		}
	}

	private function infinite(CommandSender $sender, Player $target, Effect $effect, int $amplifier = 0, bool $hideParticles = false) : void{
		if($this->canTarget($sender, $target, DefaultPermissionNames::COMMAND_EFFECT_SELF, DefaultPermissionNames::COMMAND_EFFECT_OTHER)){
			$this->apply($sender, $target, $effect, null, true, $amplifier, $hideParticles);
		}
	}

	private function apply(CommandSender $sender, Player $target, Effect $effect, ?int $seconds, bool $infinite, int $amplifier, bool $hideParticles) : void{
		$effects = $target->getEffects();
		if($seconds === 0){
			if(!$effects->has($effect)){
				if(count($effects->all()) === 0){
					$sender->sendMessage(KnownTranslationFactory::commands_effect_failure_notActive_all($target->getDisplayName()));
				}else{
					$sender->sendMessage(KnownTranslationFactory::commands_effect_failure_notActive($effect->getName(), $target->getDisplayName()));
				}
				return;
			}

			$effects->remove($effect);
			$sender->sendMessage(KnownTranslationFactory::commands_effect_success_removed($effect->getName(), $target->getDisplayName()));
			return;
		}

		$instance = new EffectInstance($effect, $seconds === null ? null : $seconds * 20, $amplifier, !$hideParticles, infinite: $infinite);
		$effects->add($instance);

		if($infinite){
			self::broadcastCommandMessage($sender, KnownTranslationFactory::commands_effect_success_infinite($effect->getName(), (string) $instance->getAmplifier(), $target->getDisplayName()));
		}else{
			self::broadcastCommandMessage($sender, KnownTranslationFactory::commands_effect_success($effect->getName(), (string) $instance->getAmplifier(), $target->getDisplayName(), (string) ($instance->getDuration() / 20)));
		}
	}
}
