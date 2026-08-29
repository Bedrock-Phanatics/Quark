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

namespace quark\plugin;

/**
 * Handles different types of plugins
 */
interface PluginLoader{

	/**
	 * Returns whether this PluginLoader can load the plugin in the given path.
	 */
	public function canLoadPlugin(string $path) : bool;

	/**
	 * Loads the plugin contained in $file
	 */
	public function loadPlugin(string $file) : void;

	/**
	 * Gets the PluginDescription from the file
	 * @throws PluginDescriptionParseException
	 */
	public function getPluginDescription(string $file) : ?PluginDescription;

	/**
	 * Returns the protocol prefix used to access files in this plugin, e.g. file://, phar://
	 */
	public function getAccessProtocol() : string;
}
