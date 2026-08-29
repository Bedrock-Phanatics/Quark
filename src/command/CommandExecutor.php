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

use quark\plugin\PluginBase;

/**
 * @deprecated
 *
 * Interface implemented by things that want to execute commands via {@link PluginCommand}.
 * This is implemented by {@link PluginBase} by default to allow automagically registering
 * {@link PluginBase::onCommand()} to receive commands defined in `plugin.yml`.
 */
interface CommandExecutor{

	/**
	 * @param string[] $args
	 */
	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool;

}
