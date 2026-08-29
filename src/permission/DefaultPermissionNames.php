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

namespace quark\permission;

final class DefaultPermissionNames{
	public const BROADCAST_ADMIN = "quark.broadcast.admin";
	public const BROADCAST_USER = "quark.broadcast.user";
	public const COMMAND_BAN_IP = "quark.command.ban.ip";
	public const COMMAND_BAN_LIST = "quark.command.ban.list";
	public const COMMAND_BAN_PLAYER = "quark.command.ban.player";
	public const COMMAND_CLEAR_OTHER = "quark.command.clear.other";
	public const COMMAND_CLEAR_SELF = "quark.command.clear.self";
	public const COMMAND_DEFAULTGAMEMODE = "quark.command.defaultgamemode";
	public const COMMAND_DIFFICULTY = "quark.command.difficulty";
	public const COMMAND_DUMPMEMORY = "quark.command.dumpmemory";
	public const COMMAND_EFFECT_OTHER = "quark.command.effect.other";
	public const COMMAND_EFFECT_SELF = "quark.command.effect.self";
	public const COMMAND_ENCHANT_OTHER = "quark.command.enchant.other";
	public const COMMAND_ENCHANT_SELF = "quark.command.enchant.self";
	public const COMMAND_GAMEMODE_OTHER = "quark.command.gamemode.other";
	public const COMMAND_GAMEMODE_SELF = "quark.command.gamemode.self";
	public const COMMAND_GC = "quark.command.gc";
	public const COMMAND_GIVE_OTHER = "quark.command.give.other";
	public const COMMAND_GIVE_SELF = "quark.command.give.self";
	public const COMMAND_HELP = "quark.command.help";
	public const COMMAND_KICK = "quark.command.kick";
	public const COMMAND_KILL_OTHER = "quark.command.kill.other";
	public const COMMAND_KILL_SELF = "quark.command.kill.self";
	public const COMMAND_LIST = "quark.command.list";
	public const COMMAND_ME = "quark.command.me";
	public const COMMAND_OP_GIVE = "quark.command.op.give";
	public const COMMAND_OP_TAKE = "quark.command.op.take";
	public const COMMAND_PARTICLE = "quark.command.particle";
	public const COMMAND_PLUGINS = "quark.command.plugins";
	public const COMMAND_SAVE_DISABLE = "quark.command.save.disable";
	public const COMMAND_SAVE_ENABLE = "quark.command.save.enable";
	public const COMMAND_SAVE_PERFORM = "quark.command.save.perform";
	public const COMMAND_SAY = "quark.command.say";
	public const COMMAND_SEED = "quark.command.seed";
	public const COMMAND_SETWORLDSPAWN = "quark.command.setworldspawn";
	public const COMMAND_SPAWNPOINT_OTHER = "quark.command.spawnpoint.other";
	public const COMMAND_SPAWNPOINT_SELF = "quark.command.spawnpoint.self";
	public const COMMAND_STATUS = "quark.command.status";
	public const COMMAND_STOP = "quark.command.stop";
	public const COMMAND_TELEPORT_OTHER = "quark.command.teleport.other";
	public const COMMAND_TELEPORT_SELF = "quark.command.teleport.self";
	public const COMMAND_TELL = "quark.command.tell";
	public const COMMAND_TIME_ADD = "quark.command.time.add";
	public const COMMAND_TIME_QUERY = "quark.command.time.query";
	public const COMMAND_TIME_SET = "quark.command.time.set";
	public const COMMAND_TIME_START = "quark.command.time.start";
	public const COMMAND_TIME_STOP = "quark.command.time.stop";
	public const COMMAND_TIMINGS = "quark.command.timings";
	public const COMMAND_TITLE_OTHER = "quark.command.title.other";
	public const COMMAND_TITLE_SELF = "quark.command.title.self";
	public const COMMAND_TRANSFERSERVER = "quark.command.transferserver";
	public const COMMAND_UNBAN_IP = "quark.command.unban.ip";
	public const COMMAND_UNBAN_PLAYER = "quark.command.unban.player";
	public const COMMAND_VERSION = "quark.command.version";
	public const COMMAND_WHITELIST_ADD = "quark.command.whitelist.add";
	public const COMMAND_WHITELIST_DISABLE = "quark.command.whitelist.disable";
	public const COMMAND_WHITELIST_ENABLE = "quark.command.whitelist.enable";
	public const COMMAND_WHITELIST_LIST = "quark.command.whitelist.list";
	public const COMMAND_WHITELIST_RELOAD = "quark.command.whitelist.reload";
	public const COMMAND_WHITELIST_REMOVE = "quark.command.whitelist.remove";
	public const COMMAND_XP_OTHER = "quark.command.xp.other";
	public const COMMAND_XP_SELF = "quark.command.xp.self";
	public const GROUP_CONSOLE = "quark.group.console";
	public const GROUP_OPERATOR = "quark.group.operator";
	public const GROUP_USER = "quark.group.user";
}
