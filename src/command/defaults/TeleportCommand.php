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
use quark\command\argument\RelativeRotation;
use quark\command\argument\RotationArgument;
use quark\command\Command;
use quark\command\CommandSender;
use quark\entity\Location;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use function fmod;
use function max;
use function min;
use function round;

class TeleportCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"tp",
			KnownTranslationFactory::quark_command_tp_description(),
			null,
			["teleport"]
		);
		$this->setPermissions([
			DefaultPermissionNames::COMMAND_TELEPORT_SELF,
			DefaultPermissionNames::COMMAND_TELEPORT_OTHER
		]);

		$this->syntax()
			->permission(DefaultPermissionNames::COMMAND_TELEPORT_SELF)
			->registerArgument(self::player("destination"))
			->handler($this->toPlayer(...));
		$this->syntax()
			->registerArguments([self::player("victim"), self::player("destination")])
			->handler($this->playerToPlayer(...));
		$this->syntax()
			->permission(DefaultPermissionNames::COMMAND_TELEPORT_SELF)
			->registerArguments([new PositionArgument("position"), new RotationArgument("rotation", optional: true)])
			->handler($this->toPosition(...));
		$this->syntax()
			->registerArguments([self::player("victim"), new PositionArgument("position"), new RotationArgument("rotation", optional: true)])
			->handler($this->playerToPosition(...));
	}

	private static function player(string $name) : PlayerArgument{
		return new PlayerArgument($name, partial: true, self: true);
	}

	private function toPlayer(Player $sender, Player $destination) : void{
		$this->teleportToPlayer($sender, $sender, $destination);
	}

	private function playerToPlayer(CommandSender $sender, Player $victim, Player $destination) : void{
		if($this->canTarget($sender, $victim, DefaultPermissionNames::COMMAND_TELEPORT_SELF, DefaultPermissionNames::COMMAND_TELEPORT_OTHER)){
			$this->teleportToPlayer($sender, $victim, $destination);
		}
	}

	private function toPosition(Player $sender, RelativePosition $position, ?RelativeRotation $rotation = null) : void{
		$this->teleportToPosition($sender, $sender, $position, $rotation);
	}

	private function playerToPosition(CommandSender $sender, Player $victim, RelativePosition $position, ?RelativeRotation $rotation = null) : void{
		if($this->canTarget($sender, $victim, DefaultPermissionNames::COMMAND_TELEPORT_SELF, DefaultPermissionNames::COMMAND_TELEPORT_OTHER)){
			$this->teleportToPosition($sender, $victim, $position, $rotation);
		}
	}

	private function teleportToPlayer(CommandSender $sender, Player $subject, Player $destination) : void{
		$subject->teleport($destination->getLocation());
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_tp_success($subject->getName(), $destination->getName()));
	}

	private function teleportToPosition(CommandSender $sender, Player $subject, RelativePosition $position, ?RelativeRotation $rotation) : void{
		$base = $subject->getLocation();
		$target = $this->resolvePosition($sender, $position, $base);
		if($target === null){
			return;
		}

		//omitted angles keep the current facing
		$yaw = $base->yaw;
		$pitch = $base->pitch;
		if($rotation !== null){
			$yaw = fmod($rotation->yaw->resolve($yaw), 360.0);
			$pitch = max(-90.0, min(90.0, $rotation->pitch->resolve($pitch)));
		}

		$subject->teleport(new Location($target->x, $target->y, $target->z, $base->getWorld(), $yaw, $pitch));
		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_tp_success_coordinates(
			$subject->getName(),
			(string) round($target->x, 2),
			(string) round($target->y, 2),
			(string) round($target->z, 2)
		));
	}
}
