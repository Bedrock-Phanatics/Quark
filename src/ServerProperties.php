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

namespace quark;

/**
 * @internal
 * Constants for all properties available in server.properties.
 */
final class ServerProperties{

	private function __construct(){
		//NOOP
	}

	public const AUTO_SAVE = "auto-save";
	public const DEFAULT_WORLD_GENERATOR = "level-type";
	public const DEFAULT_WORLD_GENERATOR_SETTINGS = "generator-settings";
	public const DEFAULT_WORLD_NAME = "level-name";
	public const DEFAULT_WORLD_SEED = "level-seed";
	public const DIFFICULTY = "difficulty";
	public const ENABLE_IPV6 = "enable-ipv6";
	public const ENABLE_QUERY = "enable-query";
	public const FORCE_GAME_MODE = "force-gamemode";
	public const GAME_MODE = "gamemode";
	public const HARDCORE = "hardcore";
	public const LANGUAGE = "language";
	public const MAX_PLAYERS = "max-players";
	public const MOTD = "motd";
	public const PVP = "pvp";
	public const SERVER_IPV4 = "server-ip";
	public const SERVER_IPV6 = "server-ipv6";
	public const SERVER_PORT_IPV4 = "server-port";
	public const SERVER_PORT_IPV6 = "server-portv6";
	public const VIEW_DISTANCE = "view-distance";
	public const WHITELIST = "white-list";
	public const XBOX_AUTH = "xbox-auth";
}
