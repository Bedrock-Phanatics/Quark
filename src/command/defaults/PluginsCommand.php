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

use quark\command\CommandSender;
use quark\lang\KnownTranslationFactory;
use quark\permission\DefaultPermissionNames;
use quark\plugin\Plugin;
use quark\utils\TextFormat;
use function array_map;
use function count;
use function implode;
use function sort;
use const SORT_STRING;

class PluginsCommand extends VanillaCommand{

	public function __construct(){
		parent::__construct(
			"plugins",
			KnownTranslationFactory::quark_command_plugins_description(),
			null,
			["pl"]
		);
		$this->setPermission(DefaultPermissionNames::COMMAND_PLUGINS);
	}

	public function execute(CommandSender $sender, string $commandLabel, array $args){
		$list = array_map(function(Plugin $plugin) : string{
			return ($plugin->isEnabled() ? TextFormat::GREEN : TextFormat::RED) . $plugin->getDescription()->getFullName();
		}, $sender->getServer()->getPluginManager()->getPlugins());
		sort($list, SORT_STRING);

		$sender->sendMessage(KnownTranslationFactory::quark_command_plugins_success((string) count($list), implode(TextFormat::RESET . ", ", $list)));
		return true;
	}
}
