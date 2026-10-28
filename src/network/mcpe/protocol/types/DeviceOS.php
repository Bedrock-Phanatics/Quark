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

namespace quark\network\mcpe\protocol\types;

final class DeviceOS{

	public const UNKNOWN = -1;
	public const ANDROID = 1;
	public const IOS = 2;
	public const OSX = 3;
	public const AMAZON = 4;
	public const GEAR_VR = 5;
	public const HOLOLENS = 6;
	public const WINDOWS_10 = 7;
	public const WIN32 = 8;
	public const DEDICATED = 9;
	public const TVOS = 10;
	public const PLAYSTATION = 11;
	public const NINTENDO = 12;
	public const XBOX = 13;
	public const WINDOWS_PHONE = 14;
	public const LINUX = 15;
}
