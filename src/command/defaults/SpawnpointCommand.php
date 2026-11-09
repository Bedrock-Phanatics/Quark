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

use quark\command\argument\PlayerArgument;
use quark\command\argument\PositionArgument;
use quark\command\argument\RelativePosition;
use quark\command\Command;
use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\world\Position;
use function round;

class SpawnpointCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"spawnpoint",
			KnownTranslationFactory::quark_command_spawnpoint_description()
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_SPAWNPOINT_SELF,
			DefaultPermissionNames::COMMAND_SPAWNPOINT_OTHER
		]);

		$this->syntax()
			->permission(DefaultPermissionNames::COMMAND_SPAWNPOINT_SELF)
			->handler($this->hereForSelf(...));
		$this->syntax()
			->registerArgument(self::target())
			->handler($this->hereForOther(...));
		$this->syntax()
			->permission(DefaultPermissionNames::COMMAND_SPAWNPOINT_SELF)
			->registerArgument(new PositionArgument("position"))
			->handler($this->atForSelf(...));
		$this->syntax()
			->registerArguments([self::target(), new PositionArgument("position")])
			->handler($this->atForOther(...));
	}

	private static function target() : PlayerArgument{
		return new PlayerArgument("target", partial: true, self: true);
	}

	private function hereForSelf(Player $sender) : void{
		$this->setHere($sender, $sender);
	}

	private function hereForOther(Player $sender, Player $target) : void{
		if($this->canTarget($sender, $target, DefaultPermissionNames::COMMAND_SPAWNPOINT_SELF, DefaultPermissionNames::COMMAND_SPAWNPOINT_OTHER)){
			$this->setHere($sender, $target);
		}
	}

	private function atForSelf(Player $sender, RelativePosition $position) : void{
		$this->setAt($sender, $sender, $position);
	}

	private function atForOther(CommandSender $sender, Player $target, RelativePosition $position) : void{
		if($this->canTarget($sender, $target, DefaultPermissionNames::COMMAND_SPAWNPOINT_SELF, DefaultPermissionNames::COMMAND_SPAWNPOINT_OTHER)){
			$this->setAt($sender, $target, $position);
		}
	}

	private function setHere(Player $sender, Player $target) : void{
		$position = $sender->getPosition();
		$this->apply($sender, $target, Position::fromObject($position->floor(), $position->getWorld()));
	}

	private function setAt(CommandSender $sender, Player $target, RelativePosition $position) : void{
		$resolved = $this->resolvePosition($sender, $position, ($sender instanceof Player ? $sender : $target)->getPosition());
		if($resolved !== null){
			$this->apply($sender, $target, Position::fromObject($resolved, $target->getWorld()));
		}
	}

	private function apply(CommandSender $sender, Player $target, Position $spawn) : void{
		$target->setSpawn($spawn);

		Command::broadcastCommandMessage($sender, KnownTranslationFactory::quark_command_spawnpoint_success($target->getName(), (string) round($spawn->x, 2), (string) round($spawn->y, 2), (string) round($spawn->z, 2)));
	}
}
