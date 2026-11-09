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

use pocketmine\math\Vector3;
use quark\command\argument\PositionArgument;
use quark\command\argument\RelativePosition;
use quark\command\Command;
use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\player\Player;
use quark\utils\AssumptionFailedError;
use quark\world\World;

class SetWorldSpawnCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"setworldspawn",
			KnownTranslationFactory::quark_command_setworldspawn_description()
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_SETWORLDSPAWN);

		$this->syntax()->handler($this->here(...));
		$this->syntax()->registerArgument(new PositionArgument("position"))->handler($this->at(...));
	}

	private function here(Player $sender) : void{
		$position = $sender->getPosition();
		$this->apply($sender, $position->getWorld(), $position->asVector3()->floor());
	}

	private function at(CommandSender $sender, RelativePosition $position) : void{
		if($sender instanceof Player){
			$base = $sender->getPosition();
			$world = $base->getWorld();
		}else{
			$base = new Vector3(0.0, 0.0, 0.0);
			$world = $sender->getServer()->getWorldManager()->getDefaultWorld() ?? throw new AssumptionFailedError("No default world");
		}

		$resolved = $this->resolvePosition($sender, $position, $base);
		if($resolved !== null){
			$this->apply($sender, $world, $resolved->floor());
		}
	}

	private function apply(CommandSender $sender, World $world, Vector3 $pos) : void{
		$world->setSpawnLocation($pos);

		Command::broadcastCommandMessage($sender, KnownTranslationFactory::commands_setworldspawn_success((string) $pos->x, (string) $pos->y, (string) $pos->z));
	}
}
